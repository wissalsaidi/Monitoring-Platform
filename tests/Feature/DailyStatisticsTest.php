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
use App\Services\StatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Statistiques journalières des targets (SCRUM-51) : calcul chaque heure, sans doublon, lues par les pages.
 */
class DailyStatisticsTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private Team $team;

    private MonitoringTarget $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        // Samedi 24/10/2026 12:00 à Paris (10:00 UTC)
        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'UTC'));
        $this->team = Team::factory()->create();
        $this->api = MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => '10684', 'created_at' => now()->subDays(40)]);
        $this->zabbixItems = [['itemid' => '502']];
    }

    private function compute(string $date, ?MonitoringTarget $target = null): ?TargetDailyStat
    {
        return app(StatisticsService::class)->computeDay($target ?? $this->api, CarbonImmutable::parse($date, 'Europe/Paris'));
    }

    private function alert(string $from, ?string $to, array $attributes = []): Alert
    {
        return Alert::factory()->for($this->api, 'target')->create([
            'severity' => AlertSeverity::Critical, 'status' => $to ? AlertStatus::Resolved : AlertStatus::Open, 'metric' => 'availability',
            'started_at' => CarbonImmutable::parse($from, 'Europe/Paris')->utc(), 'resolved_at' => $to ? CarbonImmutable::parse($to, 'Europe/Paris')->utc() : null,
            ...$attributes,
        ]);
    }

    public function test_a_past_day_gets_its_availability_response_time_incidents_and_mttr(): void
    {
        // Checks : 1 échec sur 4 → 75 % ; temps de réponse : mêmes valeurs (0 et 1 s) → moyenne 250 ms
        $this->zabbixHistory = [
            ['clock' => '1', 'value' => '0'], ['clock' => '2', 'value' => '1'], ['clock' => '3', 'value' => '0'], ['clock' => '4', 'value' => '0'],
        ];
        $this->alert('2026-10-23 10:00', '2026-10-23 10:10', ['metric' => 'response_time', 'severity' => AlertSeverity::High]);
        $this->alert('2026-10-23 15:00', '2026-10-23 15:30', ['metric' => 'response_time', 'severity' => AlertSeverity::High]);

        $stat = $this->compute('2026-10-23');

        $this->assertSame('2026-10-23', $stat->date->toDateString());
        $this->assertSame(86400, $stat->covered_seconds);
        $this->assertSame(4, $stat->checks);
        $this->assertSame(75.0, $stat->availability);
        $this->assertSame(250.0, $stat->avg_response_ms);
        $this->assertSame(2, $stat->incidents);
        $this->assertSame(2, $stat->resolved_incidents);
        $this->assertSame(1200, $stat->mttr_seconds);      // (10 min + 30 min) / 2

        // Journée entière, à l'heure de Paris
        $window = $this->callsTo('history.get')[0];
        $this->assertSame(CarbonImmutable::parse('2026-10-23 00:00', 'Europe/Paris')->timestamp, $window['time_from']);
        $this->assertSame(CarbonImmutable::parse('2026-10-24 00:00', 'Europe/Paris')->timestamp, $window['time_till']);
    }

    public function test_an_unavailable_incident_lowers_the_availability_even_without_failed_checks(): void
    {
        // Agent injoignable : aucune mesure en échec, mais 6 h d'incident « indisponible » sur 24 h
        $this->zabbixHistory = [['clock' => '1', 'value' => '0'], ['clock' => '2', 'value' => '0']];
        $this->alert('2026-10-23 06:00', '2026-10-23 12:00');

        $stat = $this->compute('2026-10-23');

        $this->assertSame(21600, $stat->downtime_seconds);
        $this->assertSame(75.0, $stat->availability);
    }

    public function test_overlapping_incidents_count_once_and_are_cut_to_the_day(): void
    {
        $this->alert('2026-10-22 22:00', '2026-10-23 02:00');                     // 2 h dans la journée
        $this->alert('2026-10-23 01:00', '2026-10-23 03:00', ['severity' => AlertSeverity::High]);    // chevauche : +1 h

        $this->assertSame(3 * 3600, $this->compute('2026-10-23')->downtime_seconds);
    }

    public function test_an_ongoing_incident_counts_until_now(): void
    {
        $this->alert('2026-10-24 11:00', null);

        $stat = $this->compute('2026-10-24');

        $this->assertSame(12 * 3600, $stat->covered_seconds);       // minuit → midi
        $this->assertSame(3600, $stat->downtime_seconds);
    }

    public function test_without_checks_nor_incident_the_availability_is_unknown(): void
    {
        $stat = $this->compute('2026-10-23');

        $this->assertNull($stat->availability);
        $this->assertNull($stat->avg_response_ms);
        $this->assertNull($stat->mttr_seconds);
    }

    public function test_a_server_has_no_response_time(): void
    {
        $server = MonitoringTarget::factory()->for($this->team)->create([
            'type' => TargetType::Server, 'url' => null, 'warning_threshold_ms' => null, 'critical_threshold_ms' => null, 'zabbix_host_id' => '10800',
            'settings' => ['address' => '10.0.0.5', 'port' => 10050, 'cpu_warning' => 70, 'cpu_critical' => 90,
                'memory_warning' => 80, 'memory_critical' => 90, 'disk_warning' => 80, 'disk_critical' => 90],
            'created_at' => now()->subDays(5),
        ]);
        $this->zabbixHistory = [['clock' => '1', 'value' => '1']];

        $stat = $this->compute('2026-10-23', $server);

        $this->assertSame(100.0, $stat->availability);
        $this->assertNull($stat->avg_response_ms);
        $this->assertCount(1, $this->callsTo('history.get'));    // la disponibilité seulement
    }

    public function test_days_before_the_creation_of_the_target_are_skipped_and_the_first_day_starts_at_its_creation(): void
    {
        $recent = MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => '10685', 'created_at' => CarbonImmutable::parse('2026-10-24 06:00', 'Europe/Paris')->utc()]);

        $this->assertNull($this->compute('2026-10-23', $recent));
        $this->assertSame(6 * 3600, $this->compute('2026-10-24', $recent)->covered_seconds);
    }

    public function test_computing_again_replaces_the_row(): void
    {
        $this->compute('2026-10-23');
        $this->zabbixHistory = [['clock' => '1', 'value' => '0']];
        Cache::flush();     // séries Zabbix gardées 30 s : relire les nouvelles mesures
        $this->compute('2026-10-23');

        $this->assertSame(1, TargetDailyStat::count());
        $this->assertSame(100.0, TargetDailyStat::sole()->availability);
    }

    public function test_the_hourly_command_computes_today_and_yesterday_of_every_target(): void
    {
        MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => '10686', 'created_at' => now()->subDays(3)]);

        $this->artisan('stats:compute')->expectsOutputToContain('4 daily statistic(s) computed, 0 failed.')->assertSuccessful();
        $this->artisan('stats:compute')->assertSuccessful();

        $this->assertSame(4, TargetDailyStat::count());
        $this->assertEqualsCanonicalizing(['2026-10-23', '2026-10-24'], TargetDailyStat::pluck('date')->map->toDateString()->unique()->values()->all());
    }

    public function test_the_command_can_fill_the_last_30_days(): void
    {
        $this->artisan('stats:compute', ['--days' => 30])->assertSuccessful();

        $this->assertSame(30, TargetDailyStat::count());
    }

    public function test_a_zabbix_failure_is_reported_by_the_command(): void
    {
        $this->failOn('history.get');

        $this->artisan('stats:compute')->expectsOutputToContain('0 daily statistic(s) computed, 2 failed.')->assertFailed();
    }

    public function test_the_job_is_scheduled_every_hour(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('stats:compute');
    }

    public function test_the_dashboard_shows_the_last_7_days_of_the_visible_targets(): void
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => TeamRole::Viewer]);
        TargetDailyStat::create(['monitoring_target_id' => $this->api->id, 'date' => '2026-10-23', 'covered_seconds' => 86400, 'checks' => 1440,
            'availability' => 99.5, 'avg_response_ms' => 312, 'response_samples' => 1440, 'incidents' => 3, 'resolved_incidents' => 3, 'mttr_seconds' => 125, 'computed_at' => now()]);
        // Target d'une autre team : ne compte pas
        $other = MonitoringTarget::factory()->create();
        TargetDailyStat::create(['monitoring_target_id' => $other->id, 'date' => '2026-10-23', 'covered_seconds' => 86400, 'availability' => 10, 'incidents' => 50, 'computed_at' => now()]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Last 7 days', 'Availability', '99.50 %', 'Incidents', '3', 'Mean time to resolve', '2m 5s', 'Average response', '312 ms'])
            ->assertDontSee('10 %');

        $this->assertSame([], $this->calls);    // aucun appel à Zabbix
    }

    public function test_the_dashboard_says_when_no_statistic_exists_yet(): void
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => TeamRole::Viewer]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Statistics are computed every hour.');
    }
}
