<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Exceptions\Zabbix\ZabbixApiException;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\Provisioners\ServerProvisioner;
use App\Services\AlertService;
use App\Services\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Serveur Linux : CPU, mémoire, disque (SCRUM-69).
 */
class ServerTargetTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private Team $team;

    private const SETTINGS = [
        'address' => 'db-01.internal', 'port' => 10050,
        'cpu_warning' => 70, 'cpu_critical' => 90,
        'memory_warning' => 80, 'memory_critical' => 95,
        'disk_warning' => 75, 'disk_critical' => 85,
    ];

    private const SERVER = ['name' => 'DB-01', 'check_interval' => 60, 'settings' => self::SETTINGS];

    /** Les 7 triggers d'un serveur, tels que trigger.get les renvoie */
    private const ALL_TRIGGERS = [
        ['triggerid' => '500', 'tags' => [['tag' => 'level', 'value' => 'unavailable']]],
        ['triggerid' => '501', 'tags' => [['tag' => 'level', 'value' => 'cpu_critical']]],
        ['triggerid' => '502', 'tags' => [['tag' => 'level', 'value' => 'cpu_warning']]],
        ['triggerid' => '503', 'tags' => [['tag' => 'level', 'value' => 'memory_critical']]],
        ['triggerid' => '504', 'tags' => [['tag' => 'level', 'value' => 'memory_warning']]],
        ['triggerid' => '505', 'tags' => [['tag' => 'level', 'value' => 'disk_critical']]],
        ['triggerid' => '506', 'tags' => [['tag' => 'level', 'value' => 'disk_warning']]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->team = Team::factory()->create(['zabbix_hostgroup_id' => '42']);
        $this->zabbixHosts = [['hostid' => '10700', 'interfaces' => [['interfaceid' => '90', 'type' => '1']]]];
    }

    private function createServer(array $settings = []): MonitoringTarget
    {
        return app(MonitoringService::class)->createTarget($this->team, null, TargetType::Server, [...self::SERVER, 'settings' => [...self::SETTINGS, ...$settings]]);
    }

    private function existingServer(array $attributes = []): MonitoringTarget
    {
        return MonitoringTarget::factory()->for($this->team)->create([
            'type' => TargetType::Server, 'url' => null, 'warning_threshold_ms' => null, 'critical_threshold_ms' => null,
            ...self::SERVER, 'zabbix_host_id' => '10700', ...$attributes,
        ]);
    }

    private function member(TeamRole $role = TeamRole::Manager): User
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private static function level(array $trigger): string
    {
        return collect($trigger['tags'])->firstWhere('tag', 'level')['value'];
    }

    public function test_a_manager_creates_a_server_from_the_form(): void
    {
        $this->actingAs($this->member())->post(route('targets.store'), ['type' => 'server', ...self::SERVER])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $target = MonitoringTarget::sole();
        $this->assertSame(TargetType::Server, $target->type);
        $this->assertNull($target->url);
        $this->assertSame('db-01.internal', $target->setting('address'));
        $this->assertEquals(90, $target->setting('cpu_critical'));
    }

    public function test_server_fields_are_validated(): void
    {
        $this->actingAs($this->member())->post(route('targets.store'), [
            'type' => 'server', 'name' => 'Bad', 'check_interval' => 60,
            'settings' => [...self::SETTINGS, 'address' => 'not a host!', 'port' => 70000, 'disk_warning' => 90, 'disk_critical' => 85],
        ])
            ->assertSessionHasErrors([
                'settings.address' => 'The address must be an IP address or a host name.',
                'settings.port',
                'settings.disk_critical' => 'The Disk / critical threshold must be greater than the warning threshold.',
            ])
            ->assertSessionDoesntHaveErrors(['url', 'warning_threshold_ms', 'settings.cpu_critical']);

        $this->assertDatabaseCount('monitoring_targets', 0);
    }

    public function test_the_form_shows_the_fields_of_the_selected_type_only(): void
    {
        $html = $this->actingAs($this->member())->get(route('targets.create'))->assertOk()->getContent();

        // API par défaut : champs du serveur masqués et désactivés (non envoyés), URL active
        preg_match('/<input[^>]*name="settings\[address\]"[^>]*>/s', $html, $address);
        preg_match('/<input[^>]*name="url"[^>]*>/s', $html, $url);
        $this->assertStringContainsString('disabled', $address[0]);
        $this->assertStringNotContainsString('disabled', $url[0]);
        $this->assertMatchesRegularExpression('/class="row g-3 mb-3 d-none" data-type-fields="server"/', $html);
    }

    public function test_the_form_keeps_the_server_section_open_after_a_validation_error(): void
    {
        $manager = $this->member();
        $this->actingAs($manager)->from(route('targets.create'))
            ->post(route('targets.store'), ['type' => 'server', 'name' => 'DB', 'check_interval' => 60, 'settings' => ['address' => '']]);

        $html = $this->actingAs($manager)->get(route('targets.create'))->getContent();
        preg_match('/<input[^>]*name="settings\[address\]"[^>]*>/s', $html, $address);
        preg_match('/<input[^>]*name="url"[^>]*>/s', $html, $url);
        $this->assertStringNotContainsString('disabled', $address[0]);
        $this->assertStringContainsString('disabled', $url[0]);
    }

    public function test_the_host_gets_the_agent_interface_the_linux_template_and_our_thresholds(): void
    {
        $this->createServer();

        $host = $this->callsTo('host.create')[0];
        $this->assertSame(['type' => 1, 'main' => 1, 'useip' => 0, 'ip' => '', 'dns' => 'db-01.internal', 'port' => '10050'], $host['interfaces'][0]);
        $this->assertSame([['templateid' => '10001']], $host['templates']);
        $this->assertSame([['host' => ['Linux by Zabbix agent']]], array_map(fn ($c) => $c['filter'], $this->callsTo('template.get')));
        $this->assertContains(['macro' => '{$CPU.UTIL.CRIT}', 'value' => '90'], $host['macros']);
        $this->assertContains(['macro' => '{$VFS.FS.PUSED.MAX.WARN}', 'value' => '75'], $host['macros']);
    }

    public function test_an_ip_address_is_declared_as_ip(): void
    {
        $this->createServer(['address' => '10.0.0.12', 'port' => 10055]);

        $interface = $this->callsTo('host.create')[0]['interfaces'][0];
        $this->assertSame([1, '10.0.0.12', '', '10055'], [$interface['useip'], $interface['ip'], $interface['dns'], $interface['port']]);
    }

    public function test_the_disk_item_and_the_triggers_are_created(): void
    {
        $this->createServer();

        $disk = $this->callsTo('item.create')[0];
        $this->assertSame(ServerProvisioner::DISK_KEY, $disk['key_']);
        $this->assertSame('90', $disk['interfaceid']);
        $this->assertSame('60s', $disk['delay']);

        $triggers = collect($this->callsTo('trigger.create'))->keyBy(fn ($t) => self::level($t));
        $this->assertCount(7, $triggers);
        $this->assertSame('nodata(/target-1/agent.ping,3m)=1', $triggers['unavailable']['expression']);
        $this->assertSame(5, $triggers['unavailable']['priority']);

        $this->assertSame('avg(/target-1/system.cpu.util,5m)>90', $triggers['cpu_critical']['expression']);
        $this->assertSame(4, $triggers['cpu_critical']['priority']);
        $this->assertArrayNotHasKey('dependencies', $triggers['cpu_critical']);
        $this->assertSame('avg(/target-1/system.cpu.util,5m)>70', $triggers['cpu_warning']['expression']);
        $this->assertSame(2, $triggers['cpu_warning']['priority']);
        $this->assertArrayHasKey('dependencies', $triggers['cpu_warning']);

        $this->assertSame('avg(/target-1/vm.memory.util,5m)>95', $triggers['memory_critical']['expression']);
        $this->assertSame('avg(/target-1/vfs.fs.size[/,pused],5m)>75', $triggers['disk_warning']['expression']);
        $this->assertContains(['tag' => 'metric', 'value' => 'disk'], $triggers['disk_warning']['tags']);
        $this->assertArrayNotHasKey('chain', $triggers['disk_warning']);
    }

    public function test_os_uptime_and_disk_are_read_right_away(): void
    {
        $this->zabbixItems = [['itemid' => '61'], ['itemid' => '62']];

        $this->createServer();

        $this->assertSame([[['type' => 6, 'request' => ['itemid' => '61']], ['type' => 6, 'request' => ['itemid' => '62']]]], $this->callsTo('task.create'));
    }

    public function test_a_failed_first_check_request_does_not_block_the_creation(): void
    {
        $this->zabbixItems = [['itemid' => '61']];
        $this->failOn('task.create');

        $this->assertSame('10700', $this->createServer()->zabbix_host_id);
        $this->assertSame([], $this->callsTo('host.delete'));
    }

    public function test_without_the_linux_template_nothing_is_created(): void
    {
        $this->failOn('template.get', 'No permissions.');

        try {
            $this->createServer();
            $this->fail('Exception attendue');
        } catch (ZabbixApiException) {
        }

        $this->assertSame([], $this->callsTo('host.create'));
        $this->assertDatabaseCount('monitoring_targets', 0);
    }

    public function test_an_update_sends_address_thresholds_and_interval_to_zabbix(): void
    {
        $target = $this->existingServer();
        $this->zabbixItems = [['itemid' => '70']];
        $this->zabbixTriggers = self::ALL_TRIGGERS;

        app(MonitoringService::class)->updateTarget($target, [
            'check_interval' => 300,
            'settings' => [...self::SETTINGS, 'address' => '10.0.0.20', 'port' => 10060, 'cpu_critical' => 95],
        ]);

        $this->assertSame([['interfaceid' => '90', 'useip' => 1, 'ip' => '10.0.0.20', 'dns' => '', 'port' => '10060']], $this->callsTo('hostinterface.update'));
        $this->assertContains(['macro' => '{$CPU.UTIL.CRIT}', 'value' => '95'], collect($this->callsTo('host.update'))->last()['macros']);
        $this->assertSame([[['itemid' => '70', 'delay' => '300s']]], $this->callsTo('item.update'));

        $updates = collect($this->callsTo('trigger.update')[0])->keyBy('triggerid');
        $this->assertCount(7, $updates);
        $this->assertSame('avg(/target-1/system.cpu.util,5m)>95', $updates['501']['expression']);
    }

    public function test_only_our_triggers_become_alerts(): void
    {
        $target = $this->existingServer();
        $tags = fn (array $extra) => [['tag' => 'target_id', 'value' => (string) $target->id], ...$extra];
        $this->zabbixProblems = [
            [   // trigger du template Linux : tag target_id hérité du host, mais pas de "level"
                'eventid' => '9300', 'objectid' => '800', 'clock' => '1791809040', 'r_eventid' => '0', 'r_clock' => '0',
                'severity' => '2', 'name' => 'Linux: High CPU utilization', 'acknowledged' => '0',
                'tags' => $tags([['tag' => 'scope', 'value' => 'performance']]),
            ],
            [
                'eventid' => '9301', 'objectid' => '501', 'clock' => '1791809040', 'r_eventid' => '0', 'r_clock' => '0',
                'severity' => '4', 'name' => 'DB-01 CPU > 90 %', 'acknowledged' => '0',
                'tags' => $tags([['tag' => 'metric', 'value' => 'cpu'], ['tag' => 'level', 'value' => 'cpu_critical']]),
            ],
        ];

        app(AlertService::class)->syncFromZabbix();

        $alert = Alert::sole();
        $this->assertSame('9301', $alert->zabbix_event_id);
        $this->assertSame(AlertSeverity::High, $alert->severity);
        $this->assertSame(90, $alert->threshold);
        $this->assertSame('> 90 %', $alert->thresholdLabel());
        $this->assertSame('CPU', $alert->metricLabel());
    }

    public function test_the_sync_records_the_server_metrics(): void
    {
        $target = $this->existingServer();
        $item = fn (string $key, string $value) => ['itemid' => (string) crc32($key), 'hostid' => '10700', 'key_' => $key, 'lastclock' => '1791809000', 'lastvalue' => $value];
        $this->zabbixItems = [
            $item('agent.ping', '1'),
            $item('system.cpu.util', '12.5'),
            $item('vm.memory.util', '63.2'),
            $item(ServerProvisioner::DISK_KEY, '41'),
            $item('system.cpu.load[all,avg1]', '0.42'),
            $item('system.uptime', '93784'),
            $item('system.sw.os', 'Linux version 6.18.0 (gcc 14.2)'),
        ];

        app(AlertService::class)->syncFromZabbix();

        $target->refresh();
        $this->assertSame('up', $target->status->value);
        $this->assertNull($target->last_response_ms);
        $this->assertSame([
            'cpu' => 12.5, 'memory' => 63.2, 'disk' => 41, 'load' => 0.42, 'uptime' => 93784, 'os' => 'Linux version 6.18.0 (gcc 14.2)',
        ], $target->last_metrics);
    }

    public function test_the_overview_shows_the_server_resources(): void
    {
        $target = $this->existingServer(['last_metrics' => [
            'cpu' => 92.4, 'memory' => 63.2, 'disk' => 41, 'load' => 0.42, 'uptime' => 93784, 'os' => 'Linux version 6.18.0',
        ]]);

        $this->actingAs($this->member(TeamRole::Viewer))->get(route('targets.show', $target))
            ->assertOk()
            ->assertSeeInOrder(['IP / host', 'db-01.internal', 'Agent port 10050', 'Operating system', 'Linux version 6.18.0', 'Uptime', '1d 2h'])
            ->assertSeeInOrder(['CPU', '92.4', 'Memory', '63.2', 'Disk /', '41.0', 'Load (1 min)', '0.42'])
            ->assertSee('text-danger">92.4', false)        // au-dessus du seuil critical (90 %)
            ->assertDontSee('Response time chart');
    }

    public function test_the_configuration_tab_describes_the_checks(): void
    {
        $target = $this->existingServer();

        $this->actingAs($this->member(TeamRole::Viewer))->get(route('targets.show', [$target, 'tab' => 'configuration']))
            ->assertOk()
            ->assertSee(['Address', 'db-01.internal:10050', 'template &quot;Linux by Zabbix agent&quot;', 'CPU: warning above 70 %, critical above 90 %'], false)
            ->assertDontSee('Unavailable if the response is not 2xx');
    }

    public function test_lists_show_the_agent_address(): void
    {
        $this->existingServer();
        $user = $this->member(TeamRole::Viewer);

        $this->actingAs($user)->get(route('targets.index'))->assertOk()->assertSee('db-01.internal:10050');
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('db-01.internal:10050');
    }
}
