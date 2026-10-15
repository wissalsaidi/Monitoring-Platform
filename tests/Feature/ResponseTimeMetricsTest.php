<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class ResponseTimeMetricsTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private User $user;

    private MonitoringTarget $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeZabbixApi();
        $this->freezeSecond();
        $team = Team::factory()->create();
        $this->user = User::factory()->create();
        $team->members()->attach($this->user, ['role' => TeamRole::Viewer]);
        $this->target = MonitoringTarget::factory()->for($team)->create(['zabbix_host_id' => '10684']);
        $this->zabbixItems = [['itemid' => '502']];
    }

    private function fetch(string $period = '1h')
    {
        return $this->actingAs($this->user)->getJson(route('targets.metrics.response-time', [$this->target, 'period' => $period]));
    }

    public function test_it_returns_the_response_time_points_in_ms_with_stats_and_thresholds(): void
    {
        $this->zabbixHistory = [
            ['clock' => '1792050000', 'value' => '0.320'],
            ['clock' => '1792050030', 'value' => '0.780'],
            ['clock' => '1792050060', 'value' => '1.240'],
        ];

        $this->fetch()
            ->assertOk()
            ->assertJson([
                'period' => '1h',
                'from' => (now()->timestamp - 3600) * 1000,
                'to' => now()->timestamp * 1000,
                'source' => 'history',
                'points' => [[1792050000000, 320], [1792050030000, 780], [1792050060000, 1240]],
                'stats' => ['avg' => 780, 'min' => 320, 'max' => 1240, 'count' => 3],
                'thresholds' => ['warning' => 500, 'critical' => 1000],
            ]);
    }

    public function test_it_reads_the_history_of_the_response_time_item_over_the_period(): void
    {
        $this->fetch('24h');

        $this->assertSame(['web.test.time[api-check,request,resp]'], $this->callsTo('item.get')[0]['filter']['key_']);
        $this->assertSame([
            'output' => ['clock', 'value'],
            'itemids' => ['502'],
            'history' => 0,
            'time_from' => now()->timestamp - 86400,
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
        ], $this->callsTo('history.get')[0]);
    }

    public function test_the_7_day_view_uses_hourly_trends(): void
    {
        $this->zabbixTrends = [
            ['clock' => '1792054800', 'num' => '120', 'value_min' => '0.200', 'value_avg' => '0.400', 'value_max' => '1.500'],
            ['clock' => '1792051200', 'num' => '40', 'value_min' => '0.100', 'value_avg' => '0.800', 'value_max' => '0.900'],
        ];

        $this->fetch('7d')
            ->assertOk()
            ->assertJson([
                'source' => 'trends',
                'points' => [[1792051200000, 800], [1792054800000, 400]], // triés par date
                'stats' => ['avg' => 500, 'min' => 100, 'max' => 1500, 'count' => 160],
            ]);
        $this->assertSame([], $this->callsTo('history.get'));
    }

    public function test_without_trends_yet_the_7_day_view_falls_back_to_history(): void
    {
        $this->zabbixHistory = [['clock' => '1792050000', 'value' => '0.300']];

        $this->fetch('7d')->assertOk()->assertJson(['source' => 'history', 'stats' => ['count' => 1]]);
    }

    public function test_an_empty_period_returns_no_points(): void
    {
        $this->fetch()
            ->assertOk()
            ->assertJson(['points' => [], 'stats' => ['avg' => null, 'min' => null, 'max' => null, 'count' => 0]]);
    }

    public function test_a_target_not_provisioned_in_zabbix_has_no_data_and_no_zabbix_call(): void
    {
        $this->target->update(['zabbix_host_id' => null]);

        $this->fetch()->assertOk()->assertJson(['source' => 'none', 'points' => []]);
        $this->assertSame([], $this->calls);
    }

    public function test_results_are_cached_briefly(): void
    {
        $this->fetch();
        $this->fetch();

        $this->assertCount(1, $this->callsTo('history.get'));
        $this->assertCount(1, $this->callsTo('item.get'));
    }

    public function test_an_unknown_period_is_rejected(): void
    {
        $this->fetch('1y')->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_when_zabbix_is_down_a_generic_message_is_returned(): void
    {
        $this->zabbixDown = true;

        $this->fetch()
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Monitoring service temporarily unavailable.']);
    }

    public function test_users_of_other_teams_get_a_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('targets.metrics.response-time', $this->target))
            ->assertForbidden();
    }

    public function test_guests_get_a_401(): void
    {
        $this->getJson(route('targets.metrics.response-time', $this->target))->assertUnauthorized();
    }

    public function test_long_series_are_downsampled_by_time_bucket(): void
    {
        // 24 h de mesures toutes les 30 s = 2 880 points
        $points = array_map(fn ($i) => [$i * 30000, (float) ($i % 2 ? 100 : 300)], range(0, 2879));

        $sampled = MetricsService::downsample($points);

        $this->assertLessThanOrEqual(MetricsService::MAX_POINTS, count($sampled));
        $this->assertGreaterThan(MetricsService::MAX_POINTS - 5, count($sampled));
        $this->assertEqualsWithDelta(200, $sampled[10][1], 0.001); // moyenne de chaque intervalle
        $this->assertSame($points, MetricsService::downsample($points, 5000)); // assez court : inchangé
    }

    public function test_the_target_page_contains_the_chart_and_its_periods(): void
    {
        $this->withoutVite();

        $this->actingAs($this->user)
            ->get(route('targets.show', $this->target))
            ->assertSee(['data-response-time-chart', route('targets.metrics.response-time', $this->target)], false)
            ->assertSeeInOrder(['Response time', '1h', '24h', '7d', 'Average', 'Minimum', 'Maximum']);
        $this->assertSame([], $this->calls); // la page elle-même n'appelle pas Zabbix
    }
}
