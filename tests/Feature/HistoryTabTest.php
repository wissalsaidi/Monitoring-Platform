<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\TargetDailyStat;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Onglet History d'une target (SCRUM-50) : disponibilité sur 24 h / 7 j / 30 j, frise et liste des incidents avec
 * leur valeur maximale ; « — » quand il n'y a pas de mesure.
 */
class HistoryTabTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private User $user;

    private Team $team;

    private MonitoringTarget $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 15:00:00', 'UTC'));
        $this->team = Team::factory()->create();
        $this->user = User::factory()->create();
        $this->team->members()->attach($this->user, ['role' => TeamRole::Viewer]);
        $this->api = MonitoringTarget::factory()->for($this->team)->create(['name' => 'Payment API', 'zabbix_host_id' => '10684']);
        $this->zabbixItems = [['itemid' => '502']];
    }

    private function history(array $query = [], ?MonitoringTarget $target = null)
    {
        return $this->actingAs($this->user)->get(route('targets.show', [$target ?? $this->api, 'tab' => 'history', ...$query]));
    }

    private function incident(array $attributes = []): Alert
    {
        return Alert::factory()->for($this->api, 'target')->create([
            'severity' => AlertSeverity::High, 'status' => AlertStatus::Resolved, 'metric' => 'response_time', 'value' => 1250, 'threshold' => 1000,
            'started_at' => now()->subHours(3), 'resolved_at' => now()->subHours(2), ...$attributes,
        ]);
    }

    public function test_the_history_tab_reads_the_precomputed_statistics_without_calling_zabbix(): void
    {
        // Aujourd'hui 75 %, il y a 3 jours 100 % (journée entière), il y a 20 jours 50 %
        TargetDailyStat::create(['monitoring_target_id' => $this->api->id, 'date' => '2026-10-24', 'covered_seconds' => 61200, 'checks' => 100,
            'availability' => 75, 'avg_response_ms' => 200, 'response_samples' => 100, 'incidents' => 2, 'resolved_incidents' => 2, 'mttr_seconds' => 600, 'computed_at' => now()]);
        TargetDailyStat::create(['monitoring_target_id' => $this->api->id, 'date' => '2026-10-21', 'covered_seconds' => 86400, 'checks' => 1440,
            'availability' => 100, 'avg_response_ms' => 100, 'response_samples' => 300, 'incidents' => 0, 'resolved_incidents' => 0, 'computed_at' => now()]);
        TargetDailyStat::create(['monitoring_target_id' => $this->api->id, 'date' => '2026-10-04', 'covered_seconds' => 86400, 'checks' => 1440,
            'availability' => 50, 'incidents' => 1, 'resolved_incidents' => 1, 'mttr_seconds' => 3000, 'computed_at' => now()]);

        $this->history()
            ->assertOk()
            ->assertSeeInOrder(['Overview', 'Metrics', 'History', 'Alerts'])
            ->assertSeeInOrder(['Today', '75', 'Last 7 days', '89.63', 'Last 30 days', '75'], false)
            ->assertSeeInOrder(['Incidents · 30 days', '3', 'Mean time to resolve · 30 days', '23m 20s', 'Average response · 7 days', '125'])
            ->assertSee('Updated 24/10 17:00');

        $this->assertSame([], $this->callsTo('history.get'));
        $this->assertSame([], $this->callsTo('trend.get'));
    }

    public function test_without_statistics_yet_every_figure_is_a_dash(): void
    {
        $this->history()
            ->assertOk()
            ->assertSee('Statistics are computed every hour.')
            ->assertSee('No measure over this period')
            ->assertSeeInOrder(['Incidents · 30 days', '—', 'Mean time to resolve · 30 days', '—', 'Average response · 7 days', '—']);
    }

    public function test_zabbix_down_while_reading_a_peak_shows_a_dash_and_a_generic_message(): void
    {
        $this->incident(['value' => null]);
        $this->failOn('history.get');

        $this->history()
            ->assertOk()
            ->assertSee('Some values are temporarily unavailable from the monitoring service.')
            ->assertDontSee('Invalid params');
    }

    public function test_incidents_of_the_period_are_listed_with_their_maximum(): void
    {
        $this->incident(['started_at' => now()->subHours(3), 'resolved_at' => now()->subHours(2)->subMinutes(48)]);
        // Mesures pendant l'incident (en secondes) : le maximum est 1,8 s
        $this->zabbixHistory = [['clock' => '1', 'value' => '0.9'], ['clock' => '2', 'value' => '1.8'], ['clock' => '3', 'value' => '1.1']];

        $this->history()
            ->assertOk()
            ->assertSeeInOrder(['Started', 'Resolved', 'Duration', 'Severity', 'Maximum', 'Threshold'])
            ->assertSeeInOrder(['24/10/2026 14:00', '24/10/2026 14:12', '12m', 'HIGH', 'Response time', '1,800 ms', '&gt; 1,000 ms'], false);
    }

    public function test_the_maximum_of_a_resolved_incident_is_kept_and_not_read_again(): void
    {
        $alert = $this->incident();
        $this->zabbixHistory = [['clock' => '1', 'value' => '2.5']];
        $this->history();

        $this->assertSame(2500.0, $alert->fresh()->peak_value);

        $this->calls = [];
        $this->zabbixHistory = [];
        $this->history()->assertSee('2,500 ms');
        // Lectures de disponibilité seulement : plus de lecture de la fenêtre de l'incident
        $this->assertSame([], array_filter($this->callsTo('history.get'), fn ($call) => $call['time_from'] === $alert->started_at->timestamp));
    }

    public function test_an_ongoing_incident_is_measured_up_to_now_and_not_kept(): void
    {
        $alert = $this->incident(['status' => AlertStatus::Open, 'resolved_at' => null]);
        $this->zabbixHistory = [['clock' => '1', 'value' => '1.6']];

        $this->history()->assertSee('Ongoing')->assertSee('1,600 ms');

        $window = collect($this->callsTo('history.get'))->firstWhere('time_from', $alert->started_at->timestamp);
        $this->assertSame(now()->timestamp, $window['time_till']);
        $this->assertNull($alert->fresh()->peak_value);
    }

    public function test_the_value_at_the_alert_counts_when_zabbix_has_no_measure_in_the_window(): void
    {
        $this->incident(['value' => 1250]);

        $this->history()->assertSee('1,250 ms');
    }

    public function test_an_availability_incident_has_no_maximum(): void
    {
        $this->incident(['severity' => AlertSeverity::Critical, 'metric' => 'availability', 'value' => null, 'threshold' => null]);
        $this->zabbixHistory = [['clock' => '1', 'value' => '1']];

        $this->history()->assertOk()->assertSeeInOrder(['CRITICAL', 'Availability', '—', '—']);
    }

    public function test_the_memory_incident_of_a_container_reads_its_memory_in_mb(): void
    {
        $container = MonitoringTarget::factory()->for($this->team)->create([
            'type' => TargetType::Container, 'url' => null, 'warning_threshold_ms' => null, 'critical_threshold_ms' => null, 'zabbix_host_id' => '10900',
            'settings' => ['container' => 'shop', 'address' => 'zabbix-agent2', 'port' => 10050, 'memory_warning' => 256],
        ]);
        Alert::factory()->for($container, 'target')->create([
            'severity' => AlertSeverity::Warning, 'status' => AlertStatus::Resolved, 'metric' => 'container_memory', 'value' => 260, 'threshold' => 256,
            'started_at' => now()->subHour(), 'resolved_at' => now()->subMinutes(30),
        ]);
        $this->zabbixHistory = [['clock' => '1', 'value' => (string) (300 * 1048576)]];

        $this->history([], $container)->assertSee('300 MB');

        $this->assertContains(['container.memory'], array_column(array_column($this->callsTo('item.get'), 'filter'), 'key_'));
    }

    public function test_incidents_outside_the_period_are_not_shown(): void
    {
        $this->incident(['message' => 'Old incident', 'started_at' => now()->subDays(10), 'resolved_at' => now()->subDays(10)->addHour(), 'peak_value' => 9999]);
        $this->incident(['message' => 'Recent incident', 'peak_value' => 1111]);

        $this->history()->assertSee('1,111 ms')->assertDontSee('9,999 ms');
        $this->history(['period' => '30d'])->assertSee('9,999 ms');
    }

    public function test_an_incident_that_began_before_the_period_and_ended_inside_is_shown(): void
    {
        $this->incident(['started_at' => now()->subDays(8), 'resolved_at' => now()->subDays(6), 'peak_value' => 4321]);

        $this->history(['period' => '7d'])->assertSee('4,321 ms');
    }

    public function test_the_timeline_places_each_incident_on_the_period(): void
    {
        // Période 24 h ; incident de 12 h à 6 h (25 % de la frise, à partir de 50 %)
        $this->incident(['severity' => AlertSeverity::Critical, 'started_at' => now()->subHours(12), 'resolved_at' => now()->subHours(6), 'peak_value' => 1]);

        $this->history(['period' => '24h'])
            ->assertSee('incident-segment incident-segment-critical', false)
            ->assertSee('left: 50%; width: 25%', false)
            ->assertSee('aria-label="1 incident, last 24 hours"', false);
    }

    public function test_no_incident_says_so(): void
    {
        $this->history()->assertSee('No incident over this period.');
    }

    public function test_custom_dates_and_invalid_dates(): void
    {
        $this->incident(['started_at' => CarbonImmutable::parse('2026-10-05 10:00', 'Europe/Paris')->utc(), 'resolved_at' => CarbonImmutable::parse('2026-10-05 11:00', 'Europe/Paris')->utc(), 'peak_value' => 777]);

        $this->history(['from' => '2026-10-05', 'to' => '2026-10-05'])->assertSee('05/10/2026')->assertSee('777 ms');
        $this->history(['from' => '2026-10-05', 'to' => '2026-11-05'])
            ->assertSee('The end date cannot be in the future.')
            ->assertSee('Showing the last 7 days.')
            ->assertDontSee('777 ms');
    }

    public function test_incidents_are_paginated(): void
    {
        foreach (range(1, 21) as $i) {
            $this->incident(['started_at' => now()->subHours($i + 1), 'resolved_at' => now()->subHours($i), 'peak_value' => 1000 + $i]);
        }

        $this->history()->assertSee('1,001 ms')->assertDontSee('1,021 ms')->assertSee('page=2', false);
    }
}
