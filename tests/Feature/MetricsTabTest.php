<?php

namespace Tests\Feature;

use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\ProvisionerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesGrafana;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Onglet Metrics d'une target (SCRUM-49) : périodes 15 min à 30 jours ou dates choisies ; pour chaque métrique
 * (temps de réponse, code HTTP, disponibilité…) valeur actuelle, moyenne, min, max et graphique.
 */
class MetricsTabTest extends TestCase
{
    use FakesGrafana, FakesZabbix, RefreshDatabase;

    private User $user;

    private Team $team;

    private MonitoringTarget $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        // Samedi 24/10/2026 15:00 UTC (17:00 à Paris)
        $this->travelTo(CarbonImmutable::parse('2026-10-24 15:00:00', 'UTC'));
        $this->team = Team::factory()->create();
        $this->user = User::factory()->create();
        $this->team->members()->attach($this->user, ['role' => TeamRole::Viewer]);
        $this->api = MonitoringTarget::factory()->for($this->team)->create(['name' => 'Payment API', 'zabbix_host_id' => '10684']);
        $this->zabbixItems = [['itemid' => '502']];
    }

    private function series(string $metric, array $query = [], ?MonitoringTarget $target = null)
    {
        return $this->actingAs($this->user)->getJson(route('targets.metrics', [$target ?? $this->api, $metric, ...$query]));
    }

    // ─── Page ───

    public function test_the_metrics_tab_shows_one_card_per_metric_over_the_last_24_hours_by_default(): void
    {
        $this->actingAs($this->user)->get(route('targets.show', [$this->api, 'tab' => 'metrics']))
            ->assertOk()
            ->assertSeeInOrder(['Overview', 'Metrics', 'History'])
            ->assertDontSee('Coming in V1" class="nav-link disabled">Metrics', false)
            ->assertSeeInOrder(['15m', '1h', '6h', '24h', '7d', '30d'])
            ->assertSee('Last 24 hours')
            ->assertSeeInOrder(['Response time', 'HTTP code', 'Availability'])
            ->assertSeeInOrder(['Current', 'Average', 'Minimum', 'Maximum'])
            ->assertSee(e(route('targets.metrics', [$this->api, 'availability'])), false)
            ->assertSee('data-query="'.e(json_encode(['period' => '24h'])).'"', false);
    }

    public function test_a_period_link_applies_to_every_chart(): void
    {
        $this->actingAs($this->user)->get(route('targets.show', [$this->api, 'tab' => 'metrics', 'period' => '30d']))
            ->assertOk()
            ->assertSee('Last 30 days')
            ->assertSee('data-query="'.e(json_encode(['period' => '30d'])).'"', false);
    }

    public function test_custom_dates_apply_to_every_chart(): void
    {
        $this->actingAs($this->user)->get(route('targets.show', [$this->api, 'tab' => 'metrics', 'from' => '2026-10-01', 'to' => '2026-10-15']))
            ->assertOk()
            ->assertSee('01/10/2026 – 15/10/2026')
            ->assertSee('value="2026-10-01"', false)
            ->assertSee('data-query="'.e(json_encode(['from' => '2026-10-01', 'to' => '2026-10-15'])).'"', false);
    }

    public function test_invalid_dates_fall_back_to_24_hours_with_a_message(): void
    {
        $this->actingAs($this->user)->get(route('targets.show', [$this->api, 'tab' => 'metrics', 'from' => '2026-10-20', 'to' => '2026-10-30']))
            ->assertOk()
            ->assertSee('The end date cannot be in the future.')
            ->assertSee('Showing the last 24 hours.')
            ->assertSee('data-query="'.e(json_encode(['period' => '24h'])).'"', false);
    }

    public function test_the_overview_chart_keeps_its_three_periods(): void
    {
        $this->actingAs($this->user)->get(route('targets.show', $this->api))
            ->assertOk()
            ->assertSee('data-period="7d"', false)
            ->assertDontSee('data-period="30d"', false);
    }

    // ─── API interne ───

    public function test_short_periods_read_the_history_up_to_now(): void
    {
        $this->series('response_time', ['period' => '15m'])->assertOk()->assertJson(['period' => '15m', 'period_label' => 'Last 15 minutes']);
        $this->series('response_time', ['period' => '6h'])->assertOk();

        $calls = $this->callsTo('history.get');
        $this->assertSame([now()->timestamp - 900, now()->timestamp], [$calls[0]['time_from'], $calls[0]['time_till']]);
        $this->assertSame(now()->timestamp - 21600, $calls[1]['time_from']);
    }

    public function test_30_days_use_hourly_trends(): void
    {
        $this->zabbixTrends = [
            ['clock' => '1790000000', 'num' => '60', 'value_min' => '0.1', 'value_avg' => '0.2', 'value_max' => '0.9'],
            ['clock' => '1790003600', 'num' => '60', 'value_min' => '0.2', 'value_avg' => '0.4', 'value_max' => '1.5'],
        ];

        $this->series('response_time', ['period' => '30d'])
            ->assertOk()
            ->assertJson([
                'source' => 'trends',
                'stats' => ['last' => 400, 'avg' => 300, 'min' => 100, 'max' => 1500, 'count' => 120],
            ]);

        $this->assertSame(now()->timestamp - 2592000, $this->callsTo('trend.get')[0]['time_from']);
        $this->assertSame([], $this->callsTo('history.get'));
    }

    public function test_custom_dates_cover_whole_days_in_the_display_timezone(): void
    {
        $this->series('response_time', ['from' => '2026-10-20', 'to' => '2026-10-21'])
            ->assertOk()
            ->assertJson(['period' => 'custom', 'period_label' => '20/10/2026 – 21/10/2026']);

        $call = $this->callsTo('history.get')[0];
        $this->assertSame(CarbonImmutable::parse('2026-10-20 00:00:00', 'Europe/Paris')->timestamp, $call['time_from']);
        $this->assertSame(CarbonImmutable::parse('2026-10-21 23:59:59', 'Europe/Paris')->timestamp, $call['time_till']);
    }

    public function test_custom_dates_up_to_today_stop_at_now(): void
    {
        $this->series('response_time', ['from' => '2026-10-24', 'to' => '2026-10-24'])->assertOk()->assertJson(['to' => now()->timestamp * 1000]);
    }

    public function test_a_custom_range_of_a_week_or_more_uses_trends(): void
    {
        $this->series('response_time', ['from' => '2026-09-01', 'to' => '2026-09-30'])->assertOk();

        $this->assertCount(1, $this->callsTo('trend.get'));
    }

    public function test_invalid_ranges_are_refused(): void
    {
        $this->series('response_time', ['period' => '2h'])->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->series('response_time', ['from' => '2026-10-10', 'to' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->series('response_time', ['from' => '2026-10-10', 'to' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->series('response_time', ['from' => '2024-01-01', 'to' => '2024-01-31'])->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->series('response_time', ['from' => '2026-10-10'])->assertUnprocessable()->assertJsonValidationErrors('to');

        $this->assertSame([], $this->callsTo('history.get'));
    }

    public function test_the_availability_of_a_web_check_is_the_share_of_successful_runs(): void
    {
        // web.test.fail : 0 = toutes les étapes ont réussi
        $this->zabbixHistory = [
            ['clock' => '1792850000', 'value' => '0'],
            ['clock' => '1792850060', 'value' => '1'],
            ['clock' => '1792850120', 'value' => '0'],
            ['clock' => '1792850180', 'value' => '0'],
        ];

        $this->series('availability')
            ->assertOk()
            ->assertJson([
                'label' => 'Availability',
                'unit' => '%',
                'points' => [[1792850000000, 100], [1792850060000, 0], [1792850120000, 100], [1792850180000, 100]],
                'stats' => ['last' => 100, 'avg' => 75, 'min' => 0, 'max' => 100, 'count' => 4],
                'thresholds' => ['warning' => null, 'critical' => null],
            ]);

        $this->assertSame(['web.test.fail[api-check]'], $this->callsTo('item.get')[0]['filter']['key_']);
        $this->assertSame(3, $this->callsTo('history.get')[0]['history']);
    }

    public function test_availability_over_trends_inverts_the_failure_rate(): void
    {
        $this->zabbixTrends = [
            ['clock' => '1790000000', 'num' => '60', 'value_min' => '0', 'value_avg' => '0.25', 'value_max' => '1'],
            ['clock' => '1790003600', 'num' => '60', 'value_min' => '0', 'value_avg' => '0', 'value_max' => '0'],
        ];

        $this->series('availability', ['period' => '7d'])
            ->assertOk()
            ->assertJson(['points' => [[1790000000000, 75], [1790003600000, 100]], 'stats' => ['avg' => 87.5, 'min' => 0, 'max' => 100]]);
    }

    public function test_the_availability_of_a_server_comes_from_the_agent_ping(): void
    {
        $server = MonitoringTarget::factory()->for($this->team)->create([
            'type' => TargetType::Server, 'url' => null, 'warning_threshold_ms' => null, 'critical_threshold_ms' => null, 'zabbix_host_id' => '10800',
            'settings' => ['address' => '10.0.0.5', 'port' => 10050, 'cpu_warning' => 70, 'cpu_critical' => 90,
                'memory_warning' => 80, 'memory_critical' => 90, 'disk_warning' => 80, 'disk_critical' => 90],
        ]);
        $this->zabbixHistory = [['clock' => '1792850000', 'value' => '1'], ['clock' => '1792850060', 'value' => '1']];

        $this->series('availability', [], $server)->assertOk()->assertJson(['stats' => ['avg' => 100, 'last' => 100]]);

        $this->assertSame(['agent.ping'], $this->callsTo('item.get')[0]['filter']['key_']);
    }

    public function test_the_http_code_series_reads_the_response_code_item(): void
    {
        $this->zabbixHistory = [['clock' => '1792850000', 'value' => '200'], ['clock' => '1792850060', 'value' => '503']];

        $this->series('http_code')->assertOk()->assertJson(['label' => 'HTTP code', 'stats' => ['last' => 503, 'min' => 200, 'max' => 503]]);

        $this->assertSame(['web.test.rspcode[api-check,request]'], $this->callsTo('item.get')[0]['filter']['key_']);
    }

    public function test_every_type_offers_its_availability(): void
    {
        foreach (TargetType::cases() as $type) {
            $series = rescue(fn () => app(ProvisionerRegistry::class)->for($type)->chartSeries(), null, false);
            if ($series !== null) {
                $this->assertSame('availability', array_key_last($series), $type->value);
            }
        }
    }

    public function test_the_grafana_link_of_the_metrics_tab_keeps_custom_dates(): void
    {
        $this->fakeGrafanaApi();

        $this->actingAs($this->user)->get(route('targets.show', [$this->api, 'tab' => 'metrics', 'from' => '2026-10-20', 'to' => '2026-10-21']))
            ->assertSee(e(route('targets.grafana', [$this->api, 'from' => '2026-10-20', 'to' => '2026-10-21'])), false);

        $location = $this->actingAs($this->user)->get(route('targets.grafana', [$this->api, 'from' => '2026-10-20', 'to' => '2026-10-21']))->headers->get('Location');
        $from = CarbonImmutable::parse('2026-10-20 00:00:00', 'Europe/Paris')->timestamp * 1000;
        $to = CarbonImmutable::parse('2026-10-21 23:59:59', 'Europe/Paris')->timestamp * 1000;
        $this->assertStringContainsString("from={$from}&to={$to}", $location);
    }
}
