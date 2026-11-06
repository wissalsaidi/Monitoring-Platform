<?php

namespace Tests\Feature;

use App\Enums\AlertStatus;
use App\Enums\TeamRole;
use App\Exceptions\GrafanaException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Mail\TeamInvitationMail;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\GrafanaAccessService;
use App\Services\ZabbixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Console d'administration des teams et audit de la plateforme (SCRUM-85).
 */
class AdminTeamsTest extends TestCase
{
    use RefreshDatabase;

    private User $it;

    private Team $team;

    private MockInterface $zabbix;

    private MockInterface $grafana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        $this->zabbix = $this->mock(ZabbixService::class);
        $this->grafana = $this->mock(GrafanaAccessService::class)->shouldIgnoreMissing();
        $this->it = User::factory()->superAdmin()->create(['name' => 'IT Admin']);
        $this->team = Team::factory()->create(['name' => 'Payments', 'zabbix_hostgroup_id' => '50', 'grafana_org_id' => 7]);
        $this->team->members()->attach(User::factory()->create(['name' => 'Boss']), ['role' => TeamRole::Admin]);
    }

    private function admin(string $route, mixed $params = []): static
    {
        return $this->actingAs($this->it)->from(route($route, $params));
    }

    // ─── Accès ──────────────────────────────────────────────────────────

    public function test_only_super_admins_manage_teams_and_read_the_platform_audit(): void
    {
        $boss = $this->team->members()->first();

        foreach ([route('admin.teams.index'), route('admin.teams.show', $this->team), route('admin.audit')] as $url) {
            $this->actingAs($boss)->get($url)->assertForbidden();
            $this->actingAs($this->it)->get($url)->assertOk()->assertSee(['Users', 'Teams', 'Platform audit']);
        }
        $this->actingAs($boss)->post(route('admin.teams.store'), ['name' => 'X', 'admin_email' => 'x@company.com'])->assertForbidden();
        $this->actingAs($boss)->delete(route('admin.teams.destroy', $this->team), ['confirm_name' => 'Payments'])->assertForbidden();
        $this->assertModelExists($this->team);
    }

    // ─── Liste ──────────────────────────────────────────────────────────

    public function test_the_list_shows_members_targets_active_alerts_and_admins(): void
    {
        $target = MonitoringTarget::factory()->for($this->team)->create();
        Alert::factory()->for($target, 'target')->count(2)->create();
        Alert::factory()->for($target, 'target')->create(['status' => AlertStatus::Resolved, 'resolved_at' => now()]);
        $this->team->members()->attach(User::factory()->create(), ['role' => TeamRole::Viewer]);
        Team::factory()->create(['name' => 'Orphans']);

        $this->actingAs($this->it)->get(route('admin.teams.index'))->assertOk()
            ->assertSeeInOrder(['Orphans', 'No Admin', 'Payments', 'Boss', '2', '1', '2'])
            ->assertSee('#'.$this->team->id);
    }

    // ─── Création ───────────────────────────────────────────────────────

    public function test_create_a_team_with_an_existing_account_as_first_admin(): void
    {
        $sara = User::factory()->create(['name' => 'Sara', 'email' => 'sara@company.com']);
        $this->zabbix->shouldReceive('getHostGroups')->once()->andReturn([]);
        $this->zabbix->shouldReceive('createHostGroup')->once()->with(\Mockery::pattern('/^Monitoring Platform\/Ops #\d+$/'))->andReturn('91');
        $this->grafana->shouldReceive('syncTeam')->once();

        $response = $this->admin('admin.teams.index')->post(route('admin.teams.store'), ['name' => ' Ops ', 'admin_email' => 'SARA@company.com']);

        $ops = Team::where('name', 'Ops')->sole();
        $response->assertRedirect(route('admin.teams.show', $ops))->assertSessionHas('success', 'Team Ops created. Sara is its Admin.');
        $this->assertSame(TeamRole::Admin, $sara->roleIn($ops));
        $this->assertSame('91', $ops->fresh()->zabbix_hostgroup_id);
        $this->assertSame(count(AlertRule::defaults()), $ops->alertRules()->count());   // règles de notification par défaut
        $log = AuditLog::where('action', 'team.create')->sole();
        $this->assertSame([$this->it->id, $ops->id], [$log->user_id, $log->team_id]);
        $this->assertSame(['name' => 'Ops', 'admin' => 'sara@company.com'], $log->details);
        Mail::assertNothingQueued();
    }

    public function test_create_a_team_with_an_invited_first_admin(): void
    {
        $this->zabbix->shouldReceive('getHostGroups')->andReturn([]);
        $this->zabbix->shouldReceive('createHostGroup')->andReturn('92');

        $this->admin('admin.teams.index')->post(route('admin.teams.store'), ['name' => 'Ops', 'admin_email' => 'new@company.com'])
            ->assertSessionHas('success', 'Team Ops created. An invitation was sent to new@company.com as Admin.');

        $ops = Team::where('name', 'Ops')->sole();
        $this->assertSame(0, $ops->members()->count());
        $this->assertSame(TeamRole::Admin, TeamInvitation::where('team_id', $ops->id)->sole()->role);
        Mail::assertQueued(TeamInvitationMail::class, fn ($mail) => $mail->hasTo('new@company.com'));
        $this->assertSame('new@company.com', AuditLog::where('action', 'team.create')->sole()->details['invited_admin']);
    }

    public function test_zabbix_or_grafana_down_does_not_block_the_creation(): void
    {
        $this->zabbix->shouldReceive('getHostGroups')->andThrow(new ZabbixException('Zabbix unreachable'));
        $this->grafana->shouldReceive('syncTeam')->andThrow(new GrafanaException('Grafana unreachable'));

        $this->admin('admin.teams.index')->post(route('admin.teams.store'), ['name' => 'Ops', 'admin_email' => 'new@company.com'])
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'Zabbix host group could not be created yet') && str_contains($w, 'grafana:sync'));

        $this->assertNull(Team::where('name', 'Ops')->sole()->zabbix_hostgroup_id);
    }

    public function test_creation_is_validated(): void
    {
        User::factory()->create(['email' => 'gone@company.com', 'deactivated_at' => now()]);

        $this->admin('admin.teams.index')->post(route('admin.teams.store'), ['name' => 'Payments', 'admin_email' => 'x@company.com'])
            ->assertSessionHasErrors('name');
        $this->admin('admin.teams.index')->post(route('admin.teams.store'), ['name' => 'Ops', 'admin_email' => 'not-an-email'])
            ->assertSessionHasErrors('admin_email');
        $this->admin('admin.teams.index')->post(route('admin.teams.store'), ['name' => 'Ops', 'admin_email' => 'gone@company.com'])
            ->assertSessionHasErrors(['admin_email' => 'This account is deactivated. Reactivate it first, or choose someone else.']);

        $this->assertSame(1, Team::count());
    }

    // ─── Renommage ──────────────────────────────────────────────────────

    public function test_rename_a_team_with_zabbix_and_grafana(): void
    {
        $this->zabbix->shouldReceive('renameHostGroup')->once()->with('50', 'Monitoring Platform/Billing #'.$this->team->id);
        $this->grafana->shouldReceive('renameTeam')->once();

        $this->admin('admin.teams.show', $this->team)->patch(route('admin.teams.update', $this->team), ['name' => 'Billing'])
            ->assertSessionHas('success', 'Team Payments renamed to Billing.');

        $this->assertSame('Billing', $this->team->fresh()->name);
        $log = AuditLog::where('action', 'team.rename')->sole();
        $this->assertSame(['changes' => ['name' => ['Payments', 'Billing']]], $log->details);
        $this->assertSame([['label' => 'Name', 'old' => 'Payments', 'new' => 'Billing']], $log->detailLines());
    }

    public function test_a_rename_failing_in_zabbix_keeps_the_new_name_and_warns(): void
    {
        $this->zabbix->shouldReceive('renameHostGroup')->andThrow(new ZabbixException('down'));

        $this->admin('admin.teams.show', $this->team)->patch(route('admin.teams.update', $this->team), ['name' => 'Billing'])
            ->assertSessionHas('warning', 'Zabbix still shows the old name of the host group. Rename the team again to retry.');

        $this->assertSame('Billing', $this->team->fresh()->name);
    }

    public function test_rename_validation_and_no_change(): void
    {
        Team::factory()->create(['name' => 'Ops']);

        $this->admin('admin.teams.show', $this->team)->patch(route('admin.teams.update', $this->team), ['name' => 'Ops'])->assertSessionHasErrors('name');
        $this->admin('admin.teams.show', $this->team)->patch(route('admin.teams.update', $this->team), ['name' => 'Payments'])
            ->assertSessionHas('success', 'No change.');
        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_grafana_org_name_follows_the_team(): void
    {
        $this->assertSame('Payments #'.$this->team->id, GrafanaAccessService::orgName($this->team));
    }

    // ─── Suppression ────────────────────────────────────────────────────

    public function test_delete_a_team_after_typing_its_name(): void
    {
        $target = MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => '300']);
        Alert::factory()->for($target, 'target')->create();
        $boss = $this->team->members()->first();
        TeamInvitation::create(['team_id' => $this->team->id, 'email' => 'x@company.com', 'role' => TeamRole::Viewer, 'expires_at' => now()->addDay()]);
        AuditLog::record('target.create', $this->it, $target);

        $this->zabbix->shouldReceive('deleteHost')->once()->with('300');
        $this->zabbix->shouldReceive('deleteHostGroup')->once()->with('50');
        $this->grafana->shouldReceive('deleteTeam')->once();

        $this->admin('admin.teams.show', $this->team)->delete(route('admin.teams.destroy', $this->team), ['confirm_name' => 'Payments'])
            ->assertRedirect(route('admin.teams.index'))
            ->assertSessionHas('success', 'Team Payments deleted, with its targets and its Grafana organization.');

        $this->assertModelMissing($this->team);
        $this->assertModelMissing($target);
        $this->assertModelExists($boss);                                          // les comptes restent
        $this->assertSame(0, TeamInvitation::count());
        $log = AuditLog::where('action', 'team.delete')->sole();
        $this->assertNull($log->team_id);
        $this->assertSame(['name' => 'Payments', 'team_id' => $this->team->id, 'targets' => 1, 'members' => 1], $log->details);
        $this->assertSame('Payments (deleted)', $log->subjectLabel());

        $this->actingAs($this->it)->get(route('admin.audit', ['action' => 'team.delete']))
            ->assertSee(['Team deleted', 'Payments (deleted)', 'Deleted team']);
    }

    public function test_entries_of_a_deleted_team_keep_a_readable_label(): void
    {
        $this->zabbix->shouldReceive('renameHostGroup');
        $this->admin('admin.teams.show', $this->team)->patch(route('admin.teams.update', $this->team), ['name' => 'Billing']);
        $this->zabbix->shouldReceive('deleteHostGroup');
        $this->admin('admin.teams.show', $this->team)->delete(route('admin.teams.destroy', $this->team), ['confirm_name' => 'Billing']);

        $rename = AuditLog::where('action', 'team.rename')->sole();
        $this->assertNull($rename->team_id);
        $this->assertTrue($rename->isFromDeletedTeam());
        $this->assertSame('Team Billing (deleted)', $rename->subjectLabel());
        $this->actingAs($this->it)->get(route('admin.audit', ['action' => 'team.rename']))->assertSee('Deleted team');
    }

    public function test_a_wrong_name_deletes_nothing(): void
    {
        foreach (['payments', 'Payment', ''] as $typed) {
            $this->admin('admin.teams.show', $this->team)->delete(route('admin.teams.destroy', $this->team), ['confirm_name' => $typed])
                ->assertSessionHasErrors('confirm_name');
        }

        $this->assertModelExists($this->team);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_zabbix_down_during_deletion_keeps_the_team(): void
    {
        MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => '300']);
        $this->zabbix->shouldReceive('deleteHost')->andThrow(new ZabbixException('down'));

        $this->admin('admin.teams.show', $this->team)->delete(route('admin.teams.destroy', $this->team), ['confirm_name' => 'Payments'])
            ->assertSessionHas('error', 'Zabbix could not remove one of the targets, so the team was not deleted. Try again in a moment.');

        $this->assertModelExists($this->team);
        $this->assertSame(0, AuditLog::where('action', 'team.delete')->count());
    }

    // ─── Audit de la plateforme ─────────────────────────────────────────

    public function test_the_platform_audit_shows_every_team_and_platform_actions(): void
    {
        $ops = Team::factory()->create(['name' => 'Ops']);
        $sara = User::factory()->create(['name' => 'Sara']);
        AuditLog::record('member.invite', $this->it, $this->team, ['email' => 'first@company.com']);
        AuditLog::record('member.invite', $sara, $ops, ['email' => 'second@company.com']);
        AuditLog::record('user.deactivate', $this->it, $sara, ['email' => 'deactivated.user@corp.io']);
        AuditLog::record('platform.admin_grant', null, $this->it, ['email' => 'it@company.com', 'via' => 'console']);

        $this->actingAs($this->it)->get(route('admin.audit'))->assertOk()
            ->assertSee(['first@company.com', 'second@company.com', 'Account deactivated', 'Super Admin granted', 'Console', 'Platform', 'Payments', 'Ops']);

        $this->actingAs($this->it)->get(route('admin.audit', ['team' => 'platform']))
            ->assertSee('deactivated.user@corp.io')->assertDontSee('first@company.com');
        $this->actingAs($this->it)->get(route('admin.audit', ['team' => $ops->id]))
            ->assertSee('second@company.com')->assertDontSee('first@company.com')->assertDontSee('deactivated.user@corp.io');
        $this->actingAs($this->it)->get(route('admin.audit', ['user' => $sara->id]))
            ->assertSee('second@company.com')->assertDontSee('first@company.com');
        $this->actingAs($this->it)->get(route('admin.audit', ['action' => 'user.deactivate']))
            ->assertSee('deactivated.user@corp.io')->assertDontSee('second@company.com');
        $this->actingAs($this->it)->get(route('admin.audit', ['team' => '999']))->assertSessionHasErrors('team');
    }

    public function test_the_platform_audit_filters_by_date(): void
    {
        $this->travelTo('2026-11-01 10:00');
        AuditLog::record('member.invite', $this->it, $this->team, ['email' => 'old@company.com']);
        $this->travelTo('2026-11-05 10:00');
        AuditLog::record('member.invite', $this->it, $this->team, ['email' => 'new@company.com']);

        $this->actingAs($this->it)->get(route('admin.audit', ['from' => '2026-11-03']))
            ->assertSee('new@company.com')->assertDontSee('old@company.com');
        $this->actingAs($this->it)->get(route('admin.audit', ['to' => '2026-11-02']))
            ->assertSee('old@company.com')->assertDontSee('new@company.com');
        $this->actingAs($this->it)->get(route('admin.audit', ['from' => '2026-11-05', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
    }

    public function test_the_team_audit_page_still_works_with_the_shared_table(): void
    {
        $boss = $this->team->members()->first();
        AuditLog::record('member.invite', $boss, $this->team, ['email' => 'a@company.com']);

        $this->actingAs($boss)->get(route('teams.audit.index', $this->team))->assertOk()
            ->assertSee(['Member invited', 'a@company.com'])->assertDontSee('Platform audit');
    }
}
