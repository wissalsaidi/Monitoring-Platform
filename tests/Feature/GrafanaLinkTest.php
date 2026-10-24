<?php

namespace Tests\Feature;

use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Bouton « Open Grafana dashboard » de la page d'une target (SCRUM-47).
 */
class GrafanaLinkTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private const HEALTH_URL = 'http://grafana.internal:3000/api/health';

    private Team $team;

    private User $viewer;

    private MonitoringTarget $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        config([
            'services.grafana.url' => 'https://grafana.example.com/',
            'services.grafana.internal_url' => 'http://grafana.internal:3000',
            'services.grafana.dashboard_uid' => 'monitoring-platform-target',
        ]);

        $this->team = Team::factory()->create(['name' => 'Payments']);
        $this->viewer = User::factory()->create();
        $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer]);
        $this->api = MonitoringTarget::factory()->for($this->team)->create(['name' => 'Payment API']);
    }

    private function grafanaIsUp(): void
    {
        Http::fake([self::HEALTH_URL => Http::response(['database' => 'ok', 'version' => '11.6.0'])]);
    }

    public function test_the_target_page_offers_the_grafana_dashboard(): void
    {
        $this->actingAs($this->viewer)->get(route('targets.show', $this->api))
            ->assertOk()
            ->assertSee('Open Grafana dashboard')
            ->assertSee(e(route('targets.grafana', [$this->api, 'period' => '1h'])), false)
            ->assertSee('data-grafana-link', false);
    }

    public function test_it_opens_the_dashboard_filtered_on_the_target_and_the_period(): void
    {
        $this->grafanaIsUp();

        $response = $this->actingAs($this->viewer)->get(route('targets.grafana', [$this->api, 'period' => '24h']));

        $team = rawurlencode("Monitoring Platform/Payments #{$this->team->id}");
        $target = rawurlencode("Payment API #{$this->api->id}");
        $response->assertRedirect(
            "https://grafana.example.com/d/monitoring-platform-target?orgId=1&var-team={$team}&var-target={$target}&from=now-24h&to=now"
        );
    }

    public function test_the_period_defaults_to_one_hour(): void
    {
        $this->grafanaIsUp();

        $location = $this->actingAs($this->viewer)->get(route('targets.grafana', $this->api))->headers->get('Location');

        $this->assertStringContainsString('from=now-1h&to=now', $location);
    }

    public function test_an_unknown_period_is_refused(): void
    {
        $this->grafanaIsUp();

        $this->actingAs($this->viewer)->get(route('targets.grafana', [$this->api, 'period' => '1y']))
            ->assertSessionHasErrors('period');
    }

    public function test_grafana_down_shows_a_generic_message_on_the_target_page(): void
    {
        Http::fake([self::HEALTH_URL => Http::response('Bad gateway', 502)]);

        $this->actingAs($this->viewer)->get(route('targets.grafana', $this->api))
            ->assertRedirect(route('targets.show', $this->api));

        $this->actingAs($this->viewer)->get(route('targets.show', $this->api))
            ->assertSee('Advanced metrics are temporarily unavailable.')
            ->assertDontSee('502');
    }

    public function test_grafana_unreachable_shows_the_same_message(): void
    {
        Http::fake([self::HEALTH_URL => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: grafana')]);

        $this->actingAs($this->viewer)->get(route('targets.grafana', $this->api))
            ->assertRedirect(route('targets.show', $this->api))
            ->assertSessionHas('warning', 'Advanced metrics are temporarily unavailable.');
    }

    public function test_grafana_health_is_cached_between_clicks(): void
    {
        $this->grafanaIsUp();

        $this->actingAs($this->viewer)->get(route('targets.grafana', $this->api));
        $this->actingAs($this->viewer)->get(route('targets.grafana', $this->api));

        Http::assertSentCount(1);
    }

    public function test_targets_without_response_time_have_no_grafana_button(): void
    {
        $server = MonitoringTarget::factory()->for($this->team)->create([
            'name' => 'Web-01', 'type' => TargetType::Server, 'url' => null, 'warning_threshold_ms' => null, 'critical_threshold_ms' => null,
            'settings' => ['address' => '10.0.0.5', 'port' => 10050, 'cpu_warning' => 70, 'cpu_critical' => 90,
                'memory_warning' => 80, 'memory_critical' => 90, 'disk_warning' => 80, 'disk_critical' => 90],
        ]);

        $this->actingAs($this->viewer)->get(route('targets.show', $server))
            ->assertOk()
            ->assertDontSee('Open Grafana dashboard');
        $this->actingAs($this->viewer)->get(route('targets.grafana', $server))->assertNotFound();
    }

    public function test_a_user_outside_the_team_cannot_open_it(): void
    {
        $this->grafanaIsUp();

        $this->actingAs(User::factory()->create())->get(route('targets.grafana', $this->api))->assertForbidden();
        Http::assertNothingSent();
    }
}
