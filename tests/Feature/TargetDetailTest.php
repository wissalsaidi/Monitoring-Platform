<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\TargetStatus;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Support\ZabbixSyncHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TargetDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MonitoringTarget $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests(); // la page ne doit jamais appeler Zabbix
        $this->freezeSecond(); // « Checked 20 seconds ago » ne doit pas dépendre de la durée du test
        $team = Team::factory()->create();
        $this->user = User::factory()->create();
        $team->members()->attach($this->user, ['role' => TeamRole::Viewer]);
        $this->target = MonitoringTarget::factory()->for($team)->create([
            'name' => 'Payment API',
            'url' => 'http://fake-api:8080/health',
            'check_interval' => 30,
            'status' => TargetStatus::Warning,
            'last_checked_at' => now()->subSeconds(20),
            'last_response_ms' => 712.4,
            'zabbix_host_id' => '10684',
        ]);
    }

    private function page(string $tab = '')
    {
        return $this->actingAs($this->user)->get(route('targets.show', $tab ? [$this->target, 'tab' => $tab] : $this->target));
    }

    public function test_the_overview_shows_status_last_response_time_and_thresholds(): void
    {
        $this->page()
            ->assertOk()
            ->assertSeeInOrder(['Current state', 'Status', 'WARNING', 'Checked', '20 seconds ago'])
            ->assertSeeInOrder(['Last response time', '712', 'ms'])
            ->assertSeeInOrder(['Warning above', '500', 'Critical above', '1,000']);
    }

    public function test_the_overview_lists_only_active_alerts(): void
    {
        Alert::factory()->for($this->target, 'target')->create(['severity' => AlertSeverity::Warning, 'value' => 712, 'threshold' => 500]);
        Alert::factory()->for($this->target, 'target')->create(['status' => AlertStatus::Resolved, 'resolved_at' => now(), 'value' => 9999]);

        $this->page()
            ->assertSeeInOrder(['Active alerts', '1', 'WARNING', 'Response time', '712 ms', '&gt; 500 ms', 'OPEN'], false)
            ->assertDontSee('9,999 ms');
    }

    public function test_the_overview_says_when_there_is_no_active_alert(): void
    {
        $this->page()->assertSee('No active alert on this target.');
    }

    public function test_a_never_checked_target_waits_for_its_first_check(): void
    {
        $this->target->update(['last_checked_at' => null, 'last_response_ms' => null, 'status' => TargetStatus::Unknown]);

        $this->page()->assertSee(['UNKNOWN', 'Waiting for the first check', 'No measure yet']);
    }

    public function test_the_alerts_tab_lists_all_the_target_alerts(): void
    {
        Alert::factory()->for($this->target, 'target')->create(['message' => 'Open one']);
        Alert::factory()->for($this->target, 'target')->create([
            'status' => AlertStatus::Resolved,
            'started_at' => now()->subMinutes(30),
            'resolved_at' => now()->subMinutes(18),
        ]);
        Alert::factory()->create(); // alerte d'une autre target

        $this->page('alerts')
            ->assertOk()
            ->assertSee(['OPEN', 'RESOLVED', '12m'])
            ->assertViewHas('alerts', fn ($alerts) => $alerts->total() === 2);
    }

    public function test_the_alerts_tab_has_an_empty_state(): void
    {
        $this->page('alerts')->assertSee('No alert yet');
    }

    public function test_the_configuration_tab_shows_the_check_and_zabbix_settings(): void
    {
        $this->page('configuration')
            ->assertOk()
            ->assertSee(['http://fake-api:8080/health', '30 seconds', 'Warning &gt; 500 ms', 'Critical &gt; 1,000 ms', 'target-'.$this->target->id, '10684', 'api-check'], false);
    }

    public function test_metrics_and_history_tabs_are_announced_for_v1(): void
    {
        $this->page()->assertSee(['Metrics', 'History', 'Coming in V1']);
    }

    public function test_an_unknown_tab_falls_back_to_the_overview(): void
    {
        $this->page('nope')->assertOk()->assertViewHas('tab', 'overview');
    }

    public function test_when_the_last_sync_failed_a_generic_message_is_shown(): void
    {
        ZabbixSyncHealth::recordFailure();

        $this->page()
            ->assertOk()
            ->assertSee('Monitoring service temporarily unavailable.')
            ->assertDontSee(['Exception', 'cURL', 'api_jsonrpc']);
    }

    public function test_the_message_disappears_after_a_successful_sync(): void
    {
        ZabbixSyncHealth::recordFailure();
        ZabbixSyncHealth::recordSuccess();

        $this->page()->assertDontSee('Monitoring service temporarily unavailable.');
    }

    public function test_stale_data_is_flagged(): void
    {
        // 3 × 30 s + 2 min = 3 min 30 s sans nouvelle mesure
        $this->target->update(['last_checked_at' => now()->subMinutes(10)]);

        $this->page()->assertSee('No recent data from the monitoring service. The last check was 10 minutes ago.');
    }

    public function test_recent_data_is_not_flagged(): void
    {
        $this->page()->assertDontSee('No recent data');
    }

    public function test_users_of_other_teams_cannot_see_the_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('targets.show', $this->target))
            ->assertForbidden();
    }
}
