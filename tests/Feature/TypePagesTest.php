<?php

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Pages et dashboard adaptés à chaque type (SCRUM-72) : catégories du dashboard, colonnes de la liste,
 * graphiques propres au type, menu Monitoring par type.
 */
class TypePagesTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private Team $team;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->team = Team::factory()->create();
        $this->viewer = User::factory()->create();
        $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer]);
    }

    private function api(array $attributes = []): MonitoringTarget
    {
        return MonitoringTarget::factory()->for($this->team)->create([
            'name' => 'Payment API', 'last_response_ms' => 312, 'last_metrics' => ['http_code' => 200], ...$attributes,
        ]);
    }

    private function server(array $attributes = []): MonitoringTarget
    {
        return MonitoringTarget::factory()->for($this->team)->create([
            'name' => 'DB-01 server', 'type' => TargetType::Server, 'url' => null, 'warning_threshold_ms' => null, 'critical_threshold_ms' => null,
            'zabbix_host_id' => '10800',
            'settings' => ['address' => '10.0.0.12', 'port' => 10050, 'cpu_warning' => 70, 'cpu_critical' => 90,
                'memory_warning' => 80, 'memory_critical' => 90, 'disk_warning' => 80, 'disk_critical' => 90],
            'last_metrics' => ['cpu' => 92.5, 'memory' => 40.2, 'disk' => 81.0],
            ...$attributes,
        ]);
    }

    private function container(): MonitoringTarget
    {
        return MonitoringTarget::factory()->for($this->team)->create([
            'name' => 'Shop container', 'type' => TargetType::Container, 'url' => null, 'warning_threshold_ms' => null, 'critical_threshold_ms' => null,
            'settings' => ['container' => 'shop', 'address' => 'zabbix-agent2', 'port' => 10050, 'memory_warning' => 256],
            'last_metrics' => ['state' => 'exited', 'cpu' => 0, 'memory' => 314572800, 'restarts' => 4],
        ]);
    }

    public function test_the_dashboard_offers_the_categories_with_their_counts(): void
    {
        $this->api();
        $this->server();

        $this->actingAs($this->viewer)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['All', '2', 'APIs', '1', 'Websites', '0', 'Servers', '1', 'Containers', '0', 'Databases', '0'])
            ->assertSee(route('dashboard', ['type' => 'server']), false);
    }

    public function test_a_category_filters_kpis_targets_and_alerts(): void
    {
        $api = $this->api(['status' => TargetStatus::Up]);
        $server = $this->server(['status' => TargetStatus::Critical]);
        Alert::factory()->for($api, 'target')->create(['message' => 'Payment API is slow']);
        Alert::factory()->for($server, 'target')->create(['message' => 'DB-01 server CPU > 90 %']);

        $this->actingAs($this->viewer)->get(route('dashboard', ['type' => 'server']))
            ->assertOk()
            ->assertSee(['Servers overview', 'DB-01 server', 'Latest alerts', '1 active'])
            // Ni l'API dans les targets, ni son alerte (le tableau des alertes affiche le nom de la target)
            ->assertDontSee('Payment API')
            ->assertSee(e(route('targets.index', ['status' => 'critical', 'type' => 'server'])), false)
            // Colonnes du type serveur, pas celles d'une API
            ->assertSeeInOrder(['CPU', 'Memory', 'Disk /', 'DB-01 server', '92.5 %', '40.2 %', '81.0 %'])
            ->assertSee('text-danger">92.5 %', false);
    }

    public function test_an_unknown_category_shows_everything(): void
    {
        $this->api();
        $this->server();

        $this->actingAs($this->viewer)->get(route('dashboard', ['type' => 'network']))
            ->assertOk()
            ->assertSee(['Infrastructure overview', 'Payment API', 'DB-01 server']);
    }

    public function test_the_list_shows_a_key_metric_per_type_without_filter(): void
    {
        $this->api();
        $this->server();
        $this->container();

        $this->actingAs($this->viewer)->get(route('targets.index'))
            ->assertOk()
            ->assertSee('Key metric')
            ->assertSee(['312 ms', 'CPU 92.5 %', 'State exited']);
    }

    public function test_the_list_shows_the_columns_of_the_filtered_type(): void
    {
        $this->container();

        $this->actingAs($this->viewer)->get(route('targets.index', ['type' => 'container']))
            ->assertOk()
            ->assertSeeInOrder(['State', 'CPU', 'Memory', 'Restarts', 'Last check'])
            ->assertSeeInOrder(['Shop container', 'exited', '0.0 %', '300.0 MB', '4'])
            ->assertSee('text-warning">300.0 MB', false)
            ->assertDontSee('Key metric');
    }

    public function test_the_target_page_offers_the_charts_of_its_type(): void
    {
        $server = $this->server();

        $this->actingAs($this->viewer)->get(route('targets.show', $server))
            ->assertOk()
            ->assertSee(['data-metric-chart', route('targets.metrics', [$server, 'cpu'])], false)
            ->assertSee([route('targets.metrics', [$server, 'memory']), route('targets.metrics', [$server, 'disk'])], false)
            ->assertDontSee(route('targets.metrics', [$server, 'response_time']), false);
        $this->assertSame([], $this->calls);    // la page n'appelle pas Zabbix
    }

    public function test_a_server_chart_reads_its_item_in_percent_with_its_thresholds(): void
    {
        $server = $this->server();
        $this->zabbixItems = [['itemid' => '55']];
        $this->zabbixHistory = [['clock' => (string) now()->subMinutes(2)->timestamp, 'value' => '42.5']];

        $this->actingAs($this->viewer)->getJson(route('targets.metrics', [$server, 'cpu']))
            ->assertOk()
            ->assertJson([
                'metric' => 'cpu', 'label' => 'CPU', 'unit' => '%',
                'thresholds' => ['warning' => 70, 'critical' => 90],
                'stats' => ['avg' => 42.5, 'count' => 1],
            ]);

        $this->assertSame(['system.cpu.util'], $this->callsTo('item.get')[0]['filter']['key_']);
        $this->assertSame(0, $this->callsTo('history.get')[0]['history']);
    }

    public function test_container_memory_is_charted_in_mb(): void
    {
        $container = $this->container();
        $container->update(['zabbix_host_id' => '10801']);
        $this->zabbixItems = [['itemid' => '56']];
        $this->zabbixHistory = [['clock' => (string) now()->subMinutes(2)->timestamp, 'value' => '314572800']];

        $this->actingAs($this->viewer)->getJson(route('targets.metrics', [$container, 'memory']))
            ->assertOk()
            ->assertJson(['unit' => 'MB', 'thresholds' => ['warning' => 256, 'critical' => null], 'stats' => ['avg' => 300.0]]);

        $this->assertSame(3, $this->callsTo('history.get')[0]['history']);    // entier (octets)
    }

    public function test_a_chart_the_type_does_not_have_is_not_found(): void
    {
        $api = $this->api();

        $this->actingAs($this->viewer)->getJson(route('targets.metrics', [$api, 'cpu']))->assertNotFound();
        $this->assertSame([], $this->calls);
    }

    public function test_charts_stay_private_to_the_team(): void
    {
        $outsider = User::factory()->create();
        Team::factory()->create()->members()->attach($outsider, ['role' => TeamRole::Admin]);

        $this->actingAs($outsider)->getJson(route('targets.metrics', [$this->server(), 'cpu']))->assertForbidden();
    }

    public function test_the_monitoring_menu_lists_the_types(): void
    {
        $this->actingAs($this->viewer)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['All targets', 'APIs', 'Websites', 'Servers', 'Containers', 'Databases'])
            ->assertSee(route('targets.index', ['type' => 'database']), false);
    }
}
