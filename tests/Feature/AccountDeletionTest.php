<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Suppression de compte (RGPD-5, SCRUM-63).
 */
class AccountDeletionTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->user = User::factory()->create(['name' => 'Wissal', 'email' => 'wissal@example.com']);
        $this->team = Team::factory()->create(['name' => 'Platform', 'zabbix_hostgroup_id' => '42']);
        $this->team->members()->attach($this->user, ['role' => TeamRole::Admin]);
    }

    private function deleteAccount(string $password = 'password')
    {
        return $this->actingAs($this->user)->from(route('account.edit'))
            ->delete(route('account.destroy'), ['password' => $password]);
    }

    private function addMember(TeamRole $role, ?Team $team = null): User
    {
        $member = User::factory()->create();
        ($team ?? $this->team)->members()->attach($member, ['role' => $role]);

        return $member;
    }

    public function test_the_account_page_offers_the_deletion(): void
    {
        $this->actingAs($this->user)->get(route('dashboard'))->assertSee(route('account.edit'));
        $this->actingAs($this->user)->get(route('account.edit'))
            ->assertOk()
            ->assertSee('wissal@example.com')
            ->assertSee('Delete my account');
    }

    public function test_the_password_is_required(): void
    {
        $this->deleteAccount('wrong-password')->assertSessionHasErrorsIn('deleteAccount', 'password');

        $this->assertModelExists($this->user);
        $this->assertSame([], $this->calls);
    }

    public function test_the_account_is_deleted_and_the_user_logged_out(): void
    {
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => 'wissal@example.com', 'token' => 'x', 'created_at' => now()]);

        $this->deleteAccount()
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your account and your personal data have been deleted.');

        $this->assertGuest();
        $this->assertModelMissing($this->user);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame(0, DB::table('team_members')->where('user_id', $this->user->id)->count());
    }

    public function test_a_team_where_i_am_alone_is_deleted_with_its_targets_and_zabbix_hosts(): void
    {
        MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => '10684']);
        MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => '10685']);

        $this->deleteAccount()->assertRedirect(route('login'));

        $this->assertModelMissing($this->team);
        $this->assertSame(0, MonitoringTarget::count());
        $this->assertSame([['10684'], ['10685']], $this->callsTo('host.delete'));
        $this->assertSame([['42']], $this->callsTo('hostgroup.delete'));
    }

    public function test_if_zabbix_fails_the_account_is_kept(): void
    {
        MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => '10684']);
        $this->zabbixDown = true;

        $this->deleteAccount()->assertRedirect(route('account.edit'))->assertSessionHas('error');

        $this->assertModelExists($this->user);
        $this->assertModelExists($this->team);
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_in_a_shared_team_the_targets_stay_and_the_author_becomes_empty(): void
    {
        $this->addMember(TeamRole::Admin);
        $target = MonitoringTarget::factory()->for($this->team)->create(['created_by' => $this->user->id]);
        $alert = Alert::factory()->for($target, 'target')->create(['acknowledged_by' => $this->user->id, 'acknowledged_at' => now()]);

        $this->deleteAccount()->assertRedirect(route('login'));

        $this->assertModelExists($this->team);
        $this->assertNull($target->fresh()->created_by);
        $this->assertNull($alert->fresh()->acknowledged_by);
        $this->assertSame([], $this->callsTo('host.delete'));
        $this->assertDatabaseHas('audit_logs', ['team_id' => $this->team->id, 'action' => 'member.account_deleted', 'user_id' => null]);
    }

    public function test_the_last_admin_of_a_team_with_other_members_is_refused(): void
    {
        $this->addMember(TeamRole::Viewer);

        $this->actingAs($this->user)->get(route('account.edit'))
            ->assertSee('You are the last Admin of Platform')
            ->assertDontSee('Confirm with your password');

        $this->deleteAccount()
            ->assertRedirect(route('account.edit'))
            ->assertSessionHas('error', 'You are the last Admin of Platform. Give the Admin role to another member, or remove the other members, before deleting your account.');

        $this->assertModelExists($this->user);
        $this->assertSame([], $this->calls);
    }

    public function test_a_viewer_in_another_team_can_leave_it_by_deleting_the_account(): void
    {
        $other = Team::factory()->create();
        $this->addMember(TeamRole::Admin, $other);
        $other->members()->attach($this->user, ['role' => TeamRole::Viewer]);

        $this->deleteAccount()->assertRedirect(route('login'));

        $this->assertModelExists($other);
        $this->assertSame(1, $other->members()->count());
    }

    public function test_audit_entries_are_kept_without_personal_data(): void
    {
        $karim = $this->addMember(TeamRole::Admin);
        $mine = AuditLog::create(['user_id' => $this->user->id, 'team_id' => $this->team->id, 'action' => 'target.create', 'details' => ['name' => 'Payment API'], 'ip_address' => '203.0.113.7']);
        $aboutMe = AuditLog::create(['user_id' => $karim->id, 'team_id' => $this->team->id, 'action' => 'member.role_change', 'details' => ['user_id' => $this->user->id, 'email' => 'wissal@example.com', 'old' => 'viewer', 'new' => 'admin'], 'ip_address' => '198.51.100.2']);

        $this->deleteAccount()->assertRedirect(route('login'));

        $mine->refresh();
        $this->assertNull($mine->user_id);
        $this->assertNull($mine->ip_address);
        $this->assertSame(['name' => 'Payment API'], $mine->details);

        $aboutMe->refresh();
        $this->assertSame('deleted user', $aboutMe->details['email']);
        $this->assertSame('198.51.100.2', $aboutMe->ip_address); // IP de Karim, pas de l'utilisateur supprimé
        $this->assertSame($karim->id, $aboutMe->user_id);
    }

    public function test_pending_invitations_to_my_email_are_deleted(): void
    {
        $other = Team::factory()->create();
        TeamInvitation::create(['team_id' => $other->id, 'email' => 'wissal@example.com', 'role' => TeamRole::Viewer, 'expires_at' => now()->addDay()]);

        $this->deleteAccount()->assertRedirect(route('login'));

        $this->assertSame(0, TeamInvitation::count());
    }
}
