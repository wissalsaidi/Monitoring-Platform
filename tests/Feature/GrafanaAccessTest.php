<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\GrafanaAccessService;
use App\Services\TeamMemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesGrafana;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Accès à Grafana par team (SCRUM-48) : organisation Grafana et utilisateur Zabbix en lecture seule par team,
 * comptes Viewer des seules organisations de leurs teams, connexion par l'auth proxy.
 */
class GrafanaAccessTest extends TestCase
{
    use FakesGrafana, FakesZabbix, RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $viewer;

    private MonitoringTarget $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->fakeGrafanaApi();

        $this->team = Team::factory()->create(['name' => 'Payments', 'zabbix_hostgroup_id' => '42']);
        $this->admin = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin]);
        $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer]);
        $this->api = MonitoringTarget::factory()->for($this->team)->create(['name' => 'Payment API']);
    }

    private function open(User $user, ?MonitoringTarget $target = null): void
    {
        $this->actingAs($user)->get(route('targets.grafana', $target ?? $this->api))->assertRedirect();
    }

    public function test_the_first_click_prepares_the_team_with_a_read_only_zabbix_user(): void
    {
        $this->open($this->viewer);

        $team = $this->team->fresh();
        $group = $this->zabbixUserGroups[$team->zabbix_usrgrp_id];
        $this->assertSame([['id' => '42', 'permission' => 2]], $group['hostgroup_rights']);     // lecture, host group de la team seulement
        $this->assertSame(3, $group['gui_access']);                                              // pas d'interface Zabbix

        $user = $this->zabbixUsers[$team->zabbix_user_id];
        $this->assertSame("grafana-team-{$team->id}", $user['username']);
        $this->assertSame('1', $user['roleid']);
        $this->assertSame([['usrgrpid' => $team->zabbix_usrgrp_id]], $user['usrgrps']);
        $this->assertSame(32, strlen($user['passwd']));
    }

    public function test_the_team_gets_its_own_grafana_organization_datasource_and_dashboard(): void
    {
        $this->open($this->viewer);

        $team = $this->team->fresh();
        $orgId = $team->grafana_org_id;
        $this->assertSame("Payments #{$team->id}", $this->grafanaOrgs[$orgId]);
        $this->assertCount(1, $this->grafanaCallsTo('POST', '#^/api/plugins/alexanderzobnin-zabbix-app/settings$#'));

        $dataSource = $this->grafanaDataSources[$orgId]['zabbix'];
        $this->assertSame('alexanderzobnin-zabbix-datasource', $dataSource['type']);
        $this->assertSame("grafana-team-{$team->id}", $dataSource['jsonData']['username']);
        // Seul endroit où le mot de passe de l'utilisateur Zabbix est gardé
        $this->assertSame($this->zabbixUsers[$team->zabbix_user_id]['passwd'], $dataSource['secureJsonData']['password']);

        $this->assertSame('Target overview', $this->grafanaDashboards[$orgId]['monitoring-platform-target']['title']);
    }

    public function test_the_user_becomes_viewer_of_the_team_organization_only(): void
    {
        $this->open($this->viewer);

        $grafanaId = $this->viewer->fresh()->grafana_user_id;
        $this->assertSame("user-{$this->viewer->id}", $this->grafanaUsers[$grafanaId]);
        $this->assertSame('Viewer', $this->grafanaMembers[$this->team->fresh()->grafana_org_id][$grafanaId]);
        // Grafana l'avait aussi rattaché à l'organisation principale (dont la datasource voit tout) : retiré
        $this->assertArrayNotHasKey($grafanaId, $this->grafanaMembers[1]);
    }

    public function test_an_admin_of_the_team_is_only_viewer_in_grafana(): void
    {
        $this->open($this->admin);

        $this->assertSame('Viewer', $this->grafanaMembers[$this->team->fresh()->grafana_org_id][$this->admin->fresh()->grafana_user_id]);
    }

    public function test_later_clicks_only_check_the_membership(): void
    {
        $this->open($this->viewer);
        $this->grafanaCalls = [];
        $this->calls = [];

        $this->open($this->viewer);

        $this->assertSame([['POST', '/api/orgs/'.$this->team->fresh()->grafana_org_id.'/users'], ['PATCH', '/api/orgs/'.$this->team->fresh()->grafana_org_id.'/users/'.$this->viewer->fresh()->grafana_user_id]],
            array_map(fn (array $call) => [$call['method'], $call['path']], array_values(array_filter($this->grafanaCalls, fn ($call) => $call['path'] !== '/api/health'))));
        $this->assertSame([], $this->calls);    // aucun appel à Zabbix
    }

    public function test_a_grafana_error_shows_the_generic_message(): void
    {
        $this->grafanaFailures['POST /api/orgs'] = 500;
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message;
        });

        $this->actingAs($this->viewer)->get(route('targets.grafana', $this->api))
            ->assertRedirect(route('targets.show', $this->api))
            ->assertSessionHas('warning', 'Advanced metrics are temporarily unavailable.');

        $this->assertContains('Grafana access not granted', $logged);
    }

    public function test_a_member_removed_from_the_team_loses_the_grafana_organization(): void
    {
        $this->open($this->viewer);
        $orgId = $this->team->fresh()->grafana_org_id;

        app(TeamMemberService::class)->remove($this->team, $this->viewer->fresh(), $this->admin);

        $this->assertArrayNotHasKey($this->viewer->fresh()->grafana_user_id, $this->grafanaMembers[$orgId]);
    }

    public function test_grafana_down_does_not_block_a_member_removal(): void
    {
        $this->open($this->viewer);
        $this->grafanaDown = true;

        app(TeamMemberService::class)->remove($this->team, $this->viewer->fresh(), $this->admin);

        $this->assertFalse($this->team->members()->whereKey($this->viewer->id)->exists());
    }

    public function test_the_sync_removes_organizations_of_other_teams_and_repairs_a_missing_datasource(): void
    {
        $this->open($this->viewer);
        $team = $this->team->fresh();
        $grafanaId = $this->viewer->fresh()->grafana_user_id;
        // Membre d'une organisation qui n'est pas la sienne (retrait non répercuté), datasource supprimée à la main
        $other = $this->newOrg('Other #99');
        $this->grafanaMembers[$other][$grafanaId] = 'Viewer';
        unset($this->grafanaDataSources[$team->grafana_org_id]['zabbix']);
        $this->calls = [];

        $this->artisan('grafana:sync')->assertSuccessful();

        $this->assertArrayNotHasKey($grafanaId, $this->grafanaMembers[$other]);
        $this->assertSame('Viewer', $this->grafanaMembers[$team->grafana_org_id][$grafanaId]);
        // Nouveau mot de passe : donné à l'utilisateur Zabbix et à la datasource recréée
        $update = $this->callsTo('user.update')[0];
        $this->assertSame($team->zabbix_user_id, $update['userid']);
        $this->assertSame($update['passwd'], $this->grafanaDataSources[$team->grafana_org_id]['zabbix']['secureJsonData']['password']);
    }

    public function test_the_sync_recreates_a_deleted_grafana_account(): void
    {
        $this->open($this->viewer);
        $old = $this->viewer->fresh()->grafana_user_id;
        unset($this->grafanaUsers[$old], $this->grafanaMembers[$this->team->fresh()->grafana_org_id][$old]);

        $this->artisan('grafana:sync')->assertSuccessful();

        $new = $this->viewer->fresh()->grafana_user_id;
        $this->assertNotSame($old, $new);
        $this->assertSame('Viewer', $this->grafanaMembers[$this->team->fresh()->grafana_org_id][$new]);
    }

    public function test_deleting_an_account_removes_its_grafana_account_and_its_solo_team(): void
    {
        $solo = User::factory()->create();
        $soloTeam = Team::factory()->create(['zabbix_hostgroup_id' => '43']);
        $soloTeam->members()->attach($solo, ['role' => TeamRole::Admin]);
        $soloApi = MonitoringTarget::factory()->for($soloTeam)->create(['zabbix_host_id' => null]);
        $this->open($solo, $soloApi);
        $soloTeam->refresh();
        $grafanaId = $solo->fresh()->grafana_user_id;

        app(AccountDeletionService::class)->delete($solo->fresh());

        $this->assertArrayNotHasKey($grafanaId, $this->grafanaUsers);
        $this->assertArrayNotHasKey($soloTeam->grafana_org_id, $this->grafanaOrgs);
        $this->assertArrayNotHasKey($soloTeam->zabbix_user_id, $this->zabbixUsers);
        $this->assertArrayNotHasKey($soloTeam->zabbix_usrgrp_id, $this->zabbixUserGroups);
    }

    public function test_the_admin_password_and_the_zabbix_password_are_never_logged(): void
    {
        $messages = [];
        Log::listen(function (MessageLogged $event) use (&$messages) {
            $messages[] = $event->message.' '.json_encode($event->context);
        });

        $this->open($this->viewer);

        $zabbixPassword = $this->zabbixUsers[$this->team->fresh()->zabbix_user_id]['passwd'];
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('grafana-admin-secret', $message);
            $this->assertStringNotContainsString($zabbixPassword, $message);
        }
    }

    // ─── Auth proxy : réponse de /_grafana_auth à nginx ───

    public function test_the_auth_proxy_refuses_a_guest(): void
    {
        $this->get('/_grafana_auth')->assertStatus(401);
    }

    public function test_the_auth_proxy_refuses_an_unverified_user(): void
    {
        $user = User::factory()->unverified()->create(['grafana_user_id' => 5]);

        $this->actingAs($user)->get('/_grafana_auth')->assertStatus(401);
    }

    public function test_the_auth_proxy_refuses_a_user_without_grafana_account(): void
    {
        $this->actingAs($this->viewer)->get('/_grafana_auth')->assertStatus(403);
    }

    public function test_the_auth_proxy_gives_nginx_the_login_of_the_connected_user(): void
    {
        $this->viewer->forceFill(['grafana_user_id' => 12, 'name' => "Ann\r\nX-Evil: 1"])->save();

        $this->actingAs($this->viewer)->get('/_grafana_auth')
            ->assertOk()
            ->assertHeader('X-WEBAUTH-USER', "user-{$this->viewer->id}")
            ->assertHeader('X-WEBAUTH-NAME', 'Ann  X-Evil: 1')
            ->assertHeader('X-WEBAUTH-EMAIL', $this->viewer->email)
            ->assertHeaderMissing('X-Evil');
    }

    public function test_the_grafana_login_is_derived_from_the_user_id_not_from_a_request(): void
    {
        $this->viewer->forceFill(['grafana_user_id' => 12])->save();

        $this->actingAs($this->viewer)->withHeaders(['X-WEBAUTH-USER' => 'admin'])->get('/_grafana_auth')
            ->assertHeader('X-WEBAUTH-USER', GrafanaAccessService::login($this->viewer));
    }
}
