<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\Provisioners\ContainerProvisioner;
use App\Services\AlertService;
use App\Services\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Conteneur Docker (SCRUM-70).
 */
class ContainerTargetTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private Team $team;

    private const SETTINGS = ['container' => 'shop-api', 'address' => 'zabbix-agent2', 'port' => 10050, 'memory_warning' => 256];

    private const CONTAINER = ['name' => 'Shop API container', 'check_interval' => 60, 'settings' => self::SETTINGS];

    private const ALL_TRIGGERS = [
        ['triggerid' => '500', 'tags' => [['tag' => 'level', 'value' => 'stopped']]],
        ['triggerid' => '501', 'tags' => [['tag' => 'level', 'value' => 'restart_loop']]],
        ['triggerid' => '502', 'tags' => [['tag' => 'level', 'value' => 'memory_warning']]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->team = Team::factory()->create(['zabbix_hostgroup_id' => '42']);
        $this->zabbixHosts = [['hostid' => '10700', 'interfaces' => [['interfaceid' => '90', 'type' => '1']]]];
    }

    private function createContainer(array $settings = [], int $interval = 60): MonitoringTarget
    {
        return app(MonitoringService::class)->createTarget($this->team, null, TargetType::Container,
            [...self::CONTAINER, 'check_interval' => $interval, 'settings' => [...self::SETTINGS, ...$settings]]);
    }

    private function existingContainer(array $attributes = []): MonitoringTarget
    {
        return MonitoringTarget::factory()->for($this->team)->create([
            'type' => TargetType::Container, 'url' => null, 'warning_threshold_ms' => null, 'critical_threshold_ms' => null,
            ...self::CONTAINER, 'zabbix_host_id' => '10700', ...$attributes,
        ]);
    }

    private function member(TeamRole $role = TeamRole::Manager): User
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    public function test_a_manager_creates_a_container_from_the_form(): void
    {
        $this->actingAs($this->member())->post(route('targets.store'), ['type' => 'container', ...self::CONTAINER])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $target = MonitoringTarget::sole();
        $this->assertSame(TargetType::Container, $target->type);
        $this->assertSame('shop-api', $target->setting('container'));
        $this->assertSame('shop-api @ zabbix-agent2:10050', $target->displayAddress());
    }

    /**
     * Le nom finit dans la clé des items Zabbix : « , » ou « ] » permettraient d'y ajouter des paramètres.
     */
    public function test_the_container_name_cannot_inject_item_key_parameters(): void
    {
        foreach (['shop-api],x', 'shop api', '-shop', ''] as $name) {
            $this->actingAs($this->member())->post(route('targets.store'), [
                'type' => 'container', ...self::CONTAINER, 'settings' => [...self::SETTINGS, 'container' => $name],
            ])->assertSessionHasErrors('settings.container');
        }

        $this->assertDatabaseCount('monitoring_targets', 0);
    }

    public function test_the_form_prefills_the_platform_agent_as_docker_host(): void
    {
        $html = $this->actingAs($this->member())->get(route('targets.create'))->assertOk()->getContent();

        preg_match('/<input[^>]*id="settings-container-address"[^>]*>/s', $html, $address);
        $this->assertStringContainsString('value="zabbix-agent2"', $address[0]);
        $this->assertStringContainsString('disabled', $address[0]);   // API par défaut : section masquée
    }

    public function test_the_docker_items_are_created_on_the_agent_interface(): void
    {
        $this->createContainer();

        $this->assertSame('zabbix-agent2', $this->callsTo('host.create')[0]['interfaces'][0]['dns']);

        [$info, $stats, $dependents] = $this->callsTo('item.create');
        $this->assertSame('docker.container_info[shop-api]', $info['key_']);
        $this->assertSame('docker.container_stats[shop-api]', $stats['key_']);
        $this->assertSame(['90', '60s', '0'], [$info['interfaceid'], $info['delay'], $info['history']]);

        $byKey = collect($dependents)->keyBy('key_');
        $this->assertEqualsCanonicalizing(array_values(ContainerProvisioner::KEYS), $byKey->keys()->all());
        $this->assertSame('800', $byKey['container.running']['master_itemid']);
        $this->assertSame('$.State.Running', $byKey['container.running']['preprocessing'][0]['params']);
        $this->assertSame('801', $byKey['container.memory']['master_itemid']);
        $this->assertSame('$.memory_stats.usage', $byKey['container.memory']['preprocessing'][0]['params']);
        $this->assertSame(10, $byKey['container.net.in']['preprocessing'][1]['type']);    // octets → octets/s
    }

    public function test_the_three_alerts_are_created_in_their_own_chain(): void
    {
        $this->createContainer(interval: 120);

        $triggers = collect($this->callsTo('trigger.create'))->keyBy(fn ($t) => collect($t['tags'])->firstWhere('tag', 'level')['value']);

        $this->assertSame('last(/target-1/container.running)=0 or nodata(/target-1/container.running,360s)=1', $triggers['stopped']['expression']);
        $this->assertSame(5, $triggers['stopped']['priority']);    // CRITICAL
        $this->assertSame('last(/target-1/container.restarts)-min(/target-1/container.restarts,10m)>2', $triggers['restart_loop']['expression']);
        $this->assertSame(4, $triggers['restart_loop']['priority']);    // HIGH
        $this->assertSame('avg(/target-1/container.memory,5m)>268435456', $triggers['memory_warning']['expression']);
        $this->assertSame(2, $triggers['memory_warning']['priority']);    // WARNING

        foreach ($triggers as $trigger) {
            $this->assertArrayNotHasKey('dependencies', $trigger);
        }
    }

    public function test_no_data_waits_at_least_three_minutes(): void
    {
        $this->createContainer(interval: 30);

        $this->assertStringContainsString('nodata(/target-1/container.running,180s)=1', $this->callsTo('trigger.create')[0]['expression']);
    }

    public function test_an_update_follows_the_new_container_host_and_threshold(): void
    {
        $target = $this->existingContainer();
        $this->zabbixItems = [
            ['itemid' => '800', 'key_' => 'docker.container_info[shop-api]'],
            ['itemid' => '801', 'key_' => 'docker.container_stats[shop-api]'],
        ];
        $this->zabbixTriggers = self::ALL_TRIGGERS;

        app(MonitoringService::class)->updateTarget($target, [
            'check_interval' => 300,
            'settings' => [...self::SETTINGS, 'container' => 'shop-api-v2', 'address' => '10.0.0.30', 'memory_warning' => 1024],
        ]);

        $this->assertSame('10.0.0.30', $this->callsTo('hostinterface.update')[0]['ip']);
        $this->assertSame([[
            ['itemid' => '800', 'key_' => 'docker.container_info[shop-api-v2]', 'delay' => '300s'],
            ['itemid' => '801', 'key_' => 'docker.container_stats[shop-api-v2]', 'delay' => '300s'],
        ]], $this->callsTo('item.update'));

        $updates = collect($this->callsTo('trigger.update')[0])->keyBy('triggerid');
        $this->assertSame('avg(/target-1/container.memory,5m)>1073741824', $updates['502']['expression']);
        $this->assertStringContainsString('nodata(/target-1/container.running,900s)', $updates['500']['expression']);
    }

    public function test_the_sync_records_alerts_and_metrics(): void
    {
        $target = $this->existingContainer();
        $item = fn (string $key, string $value) => ['itemid' => (string) crc32($key), 'hostid' => '10700', 'key_' => $key, 'lastclock' => '1791809000', 'lastvalue' => $value];
        $this->zabbixItems = [
            $item('container.running', '0'),
            $item('container.status', 'exited'),
            $item('container.restarts', '7'),
            $item('container.uptime', '0'),
            $item('container.cpu', '0'),
            $item('container.memory', '0'),
            $item('container.net.in', '0'),
            $item('container.net.out', '0'),
        ];
        $problem = fn (string $id, string $severity, string $metric, string $level) => [
            'eventid' => $id, 'objectid' => '50'.$id[3], 'clock' => '1791809040', 'r_eventid' => '0', 'r_clock' => '0',
            'severity' => $severity, 'name' => "Shop $level", 'acknowledged' => '0',
            'tags' => [['tag' => 'target_id', 'value' => (string) $target->id], ['tag' => 'metric', 'value' => $metric], ['tag' => 'level', 'value' => $level]],
        ];
        $this->zabbixProblems = [$problem('9400', '5', 'availability', 'stopped'), $problem('9401', '4', 'restarts', 'restart_loop')];

        app(AlertService::class)->syncFromZabbix();

        $alerts = Alert::orderBy('zabbix_event_id')->get();
        $this->assertSame([AlertSeverity::Critical, AlertSeverity::High], $alerts->pluck('severity')->all());
        $this->assertNull($alerts[0]->threshold);
        $this->assertSame('> 2 restarts', $alerts[1]->thresholdLabel());

        $target->refresh();
        $this->assertSame('critical', $target->status->value);
        $this->assertSame('exited', $target->lastMetric('state'));
        $this->assertSame(7, $target->lastMetric('restarts'));
    }

    public function test_the_memory_alert_is_shown_in_mb(): void
    {
        $alert = new Alert(['metric' => 'container_memory', 'threshold' => 256]);

        $this->assertSame('> 256 MB', $alert->thresholdLabel());
        $this->assertSame('Memory', $alert->metricLabel());
    }

    public function test_the_overview_shows_the_container_state_and_resources(): void
    {
        $target = $this->existingContainer(['last_metrics' => [
            'state' => 'running', 'restarts' => 3, 'uptime' => 7380, 'cpu' => 1.25,
            'memory' => 314572800, 'net_in' => 2048, 'net_out' => 512,
        ]]);

        $this->actingAs($this->member(TeamRole::Viewer))->get(route('targets.show', $target))
            ->assertOk()
            ->assertSeeInOrder(['Container state', 'Running', 'shop-api', 'on zabbix-agent2:10050', 'Uptime', '2h 3m'])
            ->assertSeeInOrder(['CPU', '1.3', 'Memory', '300.0 MB', 'Warning &gt; 256 MB', 'Network', '2.0 KB/s', '512 B/s', 'Restarts', '3'], false)
            ->assertSee('text-warning">300.0 MB', false)
            ->assertDontSee('Response time chart');
    }

    public function test_a_stopped_container_is_shown_as_such(): void
    {
        $target = $this->existingContainer(['last_metrics' => ['state' => 'exited', 'uptime' => 0]]);

        $this->actingAs($this->member(TeamRole::Viewer))->get(route('targets.show', $target))
            ->assertOk()
            ->assertSee(['Exited', 'Not running']);
    }

    public function test_the_configuration_tab_describes_the_checks(): void
    {
        $target = $this->existingContainer();

        $this->actingAs($this->member(TeamRole::Viewer))->get(route('targets.show', [$target, 'tab' => 'configuration']))
            ->assertOk()
            ->assertSee(['shop-api @ zabbix-agent2:10050', 'more than 2 times in 10 minutes', 'above 256 MB']);
    }
}
