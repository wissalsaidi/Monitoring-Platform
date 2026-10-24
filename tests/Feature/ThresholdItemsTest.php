<?php

namespace Tests\Feature;

use App\Enums\TargetType;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Services\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Items de seuil (SCRUM-46) : les seuils des graphiques copiés dans Zabbix, pour le dashboard Grafana.
 */
class ThresholdItemsTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private const API_TIME_KEY = 'web.test.time[api-check,request,resp]';

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeZabbixApi();
        $this->team = Team::factory()->create(['zabbix_hostgroup_id' => '42']);
        $this->zabbixHosts = [['hostid' => '10700', 'interfaces' => [['interfaceid' => '90', 'type' => '1']]]];
    }

    private function createApi(array $data = []): MonitoringTarget
    {
        return app(MonitoringService::class)->createTarget($this->team, null, TargetType::Api, [
            'name' => 'Payment API', 'check_interval' => 60, 'url' => 'https://api.example.com/health',
            'warning_threshold_ms' => 500, 'critical_threshold_ms' => 1000, ...$data,
        ]);
    }

    /** @return list<array> items créés par item.create */
    private function createdItems(): array
    {
        return collect($this->calls)->where('method', 'item.create')
            ->flatMap(fn (array $call) => array_is_list($call['params']) ? $call['params'] : [$call['params']])
            ->filter(fn (array $item) => str_starts_with($item['key_'], 'platform.threshold['))
            ->values()->all();
    }

    public function test_an_api_gets_its_response_time_thresholds_in_seconds(): void
    {
        $this->zabbixItems = [['itemid' => '700', 'key_' => self::API_TIME_KEY, 'units' => 's']];

        $this->createApi();

        $items = collect($this->createdItems())->keyBy('key_');
        $this->assertSame(['platform.threshold[response_time,warning]', 'platform.threshold[response_time,critical]'], $items->keys()->all());

        $warning = $items['platform.threshold[response_time,warning]'];
        $this->assertSame('Threshold: Response time warning', $warning['name']);
        $this->assertSame('10700', $warning['hostid']);
        $this->assertSame(18, $warning['type']);
        $this->assertSame('700', $warning['master_itemid']);
        $this->assertSame('s', $warning['units']);
        $this->assertSame('return 0.5;', $warning['preprocessing'][0]['params']);
        $this->assertSame('return 1;', $items['platform.threshold[response_time,critical]']['preprocessing'][0]['params']);

        $search = collect($this->calls)->where('method', 'item.get')->last()['params'];
        $this->assertTrue($search['webitems']);
        $this->assertContains(self::API_TIME_KEY, $search['filter']['key_']);
    }

    public function test_a_server_gets_cpu_memory_and_disk_thresholds_in_percent(): void
    {
        $this->zabbixItems = [
            ['itemid' => '701', 'key_' => 'system.cpu.util', 'units' => '%'],
            ['itemid' => '702', 'key_' => 'vm.memory.util', 'units' => '%'],
            ['itemid' => '703', 'key_' => 'vfs.fs.size[/,pused]', 'units' => '%'],
        ];

        app(MonitoringService::class)->createTarget($this->team, null, TargetType::Server, [
            'name' => 'Web-01', 'check_interval' => 60, 'settings' => [
                'address' => '10.0.0.5', 'port' => 10050, 'cpu_warning' => 70, 'cpu_critical' => 90,
                'memory_warning' => 80, 'memory_critical' => 95, 'disk_warning' => 75, 'disk_critical' => 90,
            ],
        ]);

        $values = collect($this->createdItems())->mapWithKeys(fn (array $item) => [$item['key_'] => [$item['master_itemid'], $item['preprocessing'][0]['params']]]);
        $this->assertSame([
            'platform.threshold[cpu,warning]' => ['701', 'return 70;'],
            'platform.threshold[cpu,critical]' => ['701', 'return 90;'],
            'platform.threshold[memory,warning]' => ['702', 'return 80;'],
            'platform.threshold[memory,critical]' => ['702', 'return 95;'],
            'platform.threshold[disk,warning]' => ['703', 'return 75;'],
            'platform.threshold[disk,critical]' => ['703', 'return 90;'],
        ], $values->all());
    }

    public function test_a_container_memory_threshold_is_converted_back_to_bytes(): void
    {
        $this->zabbixItems = [['itemid' => '704', 'key_' => 'container.memory', 'units' => 'B']];

        app(MonitoringService::class)->createTarget($this->team, null, TargetType::Container, [
            'name' => 'Redis', 'check_interval' => 60,
            'settings' => ['container' => 'redis', 'address' => 'zabbix-agent2', 'port' => 10050, 'memory_warning' => 64],
        ]);

        $items = $this->createdItems();
        $this->assertCount(1, $items);   // pas de seuil critical pour la mémoire d'un conteneur
        $this->assertSame('platform.threshold[memory,warning]', $items[0]['key_']);
        $this->assertSame('return 67108864;', $items[0]['preprocessing'][0]['params']);
        $this->assertSame('B', $items[0]['units']);
    }

    public function test_changing_a_threshold_updates_the_existing_items(): void
    {
        $target = MonitoringTarget::factory()->for($this->team)->create([
            'warning_threshold_ms' => 500, 'critical_threshold_ms' => 1000, 'zabbix_host_id' => '10700',
        ]);
        $this->zabbixItems = [
            ['itemid' => '700', 'key_' => self::API_TIME_KEY, 'units' => 's'],
            ['itemid' => '710', 'key_' => 'platform.threshold[response_time,warning]', 'units' => 's'],
            ['itemid' => '711', 'key_' => 'platform.threshold[response_time,critical]', 'units' => 's'],
        ];

        app(MonitoringService::class)->updateTarget($target, ['warning_threshold_ms' => 800, 'critical_threshold_ms' => 2500]);

        $this->assertSame([], $this->createdItems());
        $updates = collect($this->calls)->where('method', 'item.update')->last()['params'];
        $this->assertSame([
            ['itemid' => '710', 'params' => 'return 0.8;'],
            ['itemid' => '711', 'params' => 'return 2.5;'],
        ], array_map(fn (array $item) => ['itemid' => $item['itemid'], 'params' => $item['preprocessing'][0]['params']], $updates));
    }

    public function test_without_the_metric_item_no_threshold_item_is_created(): void
    {
        $this->zabbixItems = [];

        $this->createApi();

        $this->assertSame([], $this->createdItems());
    }

    public function test_a_zabbix_refusal_does_not_block_the_target_creation(): void
    {
        $this->zabbixItems = [['itemid' => '700', 'key_' => self::API_TIME_KEY, 'units' => 's']];
        $this->failures['item.create'] = ['code' => -32602, 'message' => 'Invalid params.', 'data' => 'Item already exists.'];
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message;
        });

        $target = $this->createApi();

        $this->assertSame('10700', $target->fresh()->zabbix_host_id);
        $this->assertContains('Unable to sync the threshold items of a target', $logged);
        $this->assertFalse(collect($this->calls)->contains('method', 'host.delete'));
    }

    public function test_the_command_syncs_targets_created_before(): void
    {
        MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => '10700']);
        MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => null]);
        $this->zabbixItems = [['itemid' => '700', 'key_' => self::API_TIME_KEY, 'units' => 's']];

        $this->artisan('zabbix:sync-thresholds')
            ->expectsOutputToContain('1 target(s) synced, 0 failed.')
            ->assertSuccessful();

        $this->assertCount(2, $this->createdItems());
    }

    public function test_the_command_fails_when_zabbix_refuses(): void
    {
        MonitoringTarget::factory()->for($this->team)->create(['name' => 'Orders API', 'zabbix_host_id' => '10700']);
        $this->zabbixItems = [['itemid' => '700', 'key_' => self::API_TIME_KEY, 'units' => 's']];
        $this->failures['item.create'] = ['code' => -32500, 'message' => 'Application error.', 'data' => 'No permissions.'];

        $this->artisan('zabbix:sync-thresholds')
            ->expectsOutputToContain('Orders API')
            ->assertFailed();
    }
}
