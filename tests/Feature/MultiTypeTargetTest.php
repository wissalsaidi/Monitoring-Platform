<?php

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Exceptions\UnsupportedTargetTypeException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\ProvisionerRegistry;
use App\Monitoring\Provisioners\ApiProvisioner;
use App\Services\AlertService;
use App\Services\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\Fakes\FakeServerProvisioner;
use Tests\TestCase;

/**
 * Targets multi-types et provisioning Zabbix par type (SCRUM-66).
 */
class MultiTypeTargetTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private Team $team;

    private const SERVER = [
        'name' => 'Server-01',
        'check_interval' => 60,
        'settings' => ['address' => 'server-01.internal', 'port' => 10050, 'cpu_critical' => 90],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        FakeServerProvisioner::$calls = [];
        FakeServerProvisioner::$failOnCreate = false;
        // Ces tests portent sur le mécanisme commun : le type Server n'a pas de provisioner tant que enableServers()
        // n'installe pas le faux (le vrai ServerProvisioner, SCRUM-69, est testé dans ServerTargetTest)
        config(['monitoring.provisioners.server' => null]);
        $this->team = Team::factory()->create(['zabbix_hostgroup_id' => '42']);
    }

    private function enableServers(): void
    {
        config(['monitoring.provisioners.server' => FakeServerProvisioner::class]);
    }

    private function createServer(array $overrides = []): MonitoringTarget
    {
        return app(MonitoringService::class)->createTarget($this->team, null, TargetType::Server, [...self::SERVER, ...$overrides]);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => TeamRole::Manager]);

        return $user;
    }

    public function test_only_types_with_a_provisioner_are_supported(): void
    {
        $registry = app(ProvisionerRegistry::class);

        $this->assertSame([TargetType::Api, TargetType::Website], $registry->supportedTypes());
        $this->assertInstanceOf(ApiProvisioner::class, $registry->for(TargetType::Api));
        $this->assertFalse($registry->supports(TargetType::Server));

        $this->expectException(UnsupportedTargetTypeException::class);
        $registry->for(TargetType::Server);
    }

    public function test_an_unsupported_type_is_refused_before_anything_is_created(): void
    {
        try {
            $this->createServer();
            $this->fail('An unsupported type must be refused.');
        } catch (UnsupportedTargetTypeException) {
        }

        $this->assertDatabaseCount('monitoring_targets', 0);
        $this->assertSame([], $this->calls);
    }

    public function test_the_api_provisioner_declares_the_same_zabbix_fields_as_before(): void
    {
        $this->assertEqualsCanonicalizing(
            ['name', 'check_interval', 'url', 'warning_threshold_ms', 'critical_threshold_ms'],
            app(ProvisionerRegistry::class)->for(TargetType::Api)->zabbixFields(),
        );
    }

    public function test_a_new_type_is_created_with_its_own_settings_and_provisioner(): void
    {
        $this->enableServers();

        $target = $this->createServer();

        $this->assertSame(TargetType::Server, $target->type);
        $this->assertNull($target->url);
        $this->assertSame('server-01.internal', $target->fresh()->setting('address'));
        $this->assertSame(10050, $target->fresh()->setting('port'));
        $this->assertSame('fallback', $target->setting('missing', 'fallback'));

        // Partie commune : host avec tag target_id dans le host group de la team, + interface agent du provisioner
        $host = $this->callsTo('host.create')[0];
        $this->assertSame("target-{$target->id}", $host['host']);
        $this->assertSame([['groupid' => '42']], $host['groups']);
        $this->assertSame(['type' => 1, 'main' => 1, 'useip' => 0, 'ip' => '', 'dns' => 'server-01.internal', 'port' => '10050'], $host['interfaces'][0]);
        $this->assertSame([], $this->callsTo('httptest.create'));

        // Partie du type : ses triggers, chaînés et tagués pour la synchro
        $this->assertSame(['create:10700'], FakeServerProvisioner::$calls);
        $triggers = $this->callsTo('trigger.create');
        $this->assertCount(2, $triggers);
        $this->assertSame([['triggerid' => '500']], $triggers[1]['dependencies']);
        $this->assertContains(['tag' => 'metric', 'value' => 'cpu'], $triggers[1]['tags']);
        $this->assertSame('10700', $target->zabbix_host_id);
    }

    public function test_when_the_provisioner_fails_the_host_is_removed_and_nothing_is_saved(): void
    {
        $this->enableServers();
        FakeServerProvisioner::$failOnCreate = true;

        try {
            $this->createServer();
            $this->fail('The Zabbix error must be thrown.');
        } catch (ZabbixException) {
        }

        $this->assertDatabaseCount('monitoring_targets', 0);
        $this->assertSame([['10700']], $this->callsTo('host.delete'));
    }

    public function test_a_settings_change_is_sent_to_zabbix_by_the_provisioner(): void
    {
        $this->enableServers();
        $target = $this->createServer();
        $this->zabbixTriggers = [
            ['triggerid' => '500', 'tags' => [['tag' => 'level', 'value' => 'unavailable']]],
            ['triggerid' => '501', 'tags' => [['tag' => 'level', 'value' => 'critical']]],
        ];

        $changes = app(MonitoringService::class)->updateTarget($target, ['settings' => [...self::SERVER['settings'], 'cpu_critical' => 80]]);

        $this->assertSame(90, $changes['settings'][0]['cpu_critical']);
        $this->assertSame(80, $changes['settings'][1]['cpu_critical']);
        $this->assertSame(['create:10700', "update:{$target->id}"], FakeServerProvisioner::$calls);
        $this->assertStringEndsWith('system.cpu.util)>80', $this->callsTo('trigger.update')[0][1]['expression']);
    }

    public function test_a_description_change_does_not_touch_zabbix(): void
    {
        $this->enableServers();
        $target = $this->createServer();
        $before = count($this->calls);

        app(MonitoringService::class)->updateTarget($target, ['description' => 'Production web server']);

        $this->assertCount($before, $this->calls);
        $this->assertSame(['create:10700'], FakeServerProvisioner::$calls);
    }

    public function test_the_form_offers_the_supported_types_only(): void
    {
        $page = $this->actingAs($this->manager())->get(route('targets.create'));
        $page->assertOk();
        $this->assertMatchesRegularExpression('/id="type-server"[^>]*disabled/', $page->getContent());

        $this->enableServers();
        $page = $this->actingAs($this->manager())->get(route('targets.create'));
        $this->assertDoesNotMatchRegularExpression('/id="type-server"[^>]*disabled/', $page->getContent());
    }

    public function test_the_form_validates_the_fields_of_the_chosen_type(): void
    {
        $this->enableServers();
        $manager = $this->manager();

        $this->actingAs($manager)->post(route('targets.store'), ['type' => 'server', 'name' => 'Server-01', 'check_interval' => 60])
            ->assertSessionHasErrors(['settings.address' => 'The server address is required.'])
            ->assertSessionHasErrors(['settings.port', 'settings.cpu_critical'])
            ->assertSessionDoesntHaveErrors(['url', 'warning_threshold_ms']);

        $this->actingAs($manager)->post(route('targets.store'), ['type' => 'server', ...self::SERVER])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('server-01.internal', MonitoringTarget::sole()->setting('address'));
    }

    public function test_fields_of_another_type_are_not_saved(): void
    {
        $this->enableServers();

        $this->actingAs($this->manager())->post(route('targets.store'), ['type' => 'server', ...self::SERVER, 'url' => 'http://example.com'])
            ->assertSessionHasNoErrors();

        $this->assertNull(MonitoringTarget::sole()->url);
    }

    public function test_the_alert_sync_is_common_to_all_types(): void
    {
        $this->enableServers();
        $server = MonitoringTarget::factory()->for($this->team)->create([
            'type' => TargetType::Server, 'url' => null, 'warning_threshold_ms' => null, 'critical_threshold_ms' => null,
            'settings' => self::SERVER['settings'], 'zabbix_host_id' => '10800',
        ]);
        $api = MonitoringTarget::factory()->for($this->team)->create(['zabbix_host_id' => '10801']);

        $this->zabbixItems = [
            ['itemid' => '1', 'hostid' => '10800', 'key_' => 'agent.ping', 'lastclock' => '1791809000', 'lastvalue' => '1'],
            ['itemid' => '2', 'hostid' => '10801', 'key_' => 'web.test.fail[api-check]', 'lastclock' => '1791809000', 'lastvalue' => '0'],
            ['itemid' => '3', 'hostid' => '10801', 'key_' => 'web.test.time[api-check,request,resp]', 'lastclock' => '1791809000', 'lastvalue' => '0.25'],
        ];
        $this->zabbixProblems = [[
            'eventid' => '9100', 'objectid' => '700', 'clock' => '1791809040', 'r_eventid' => '0', 'r_clock' => '0',
            'severity' => '4', 'name' => 'Server-01 CPU > 90 %', 'acknowledged' => '0',
            'tags' => [['tag' => 'target_id', 'value' => (string) $server->id], ['tag' => 'metric', 'value' => 'cpu'], ['tag' => 'level', 'value' => 'critical']],
        ]];

        app(AlertService::class)->syncFromZabbix();

        // Une seule lecture d'items pour tous les types, avec les clés déclarées par chaque provisioner
        // (statut, puis métriques recopiées sur la target : code HTTP de l'API, SCRUM-68)
        $this->assertEqualsCanonicalizing(
            ['agent.ping', 'web.test.fail[api-check]', 'web.test.time[api-check,request,resp]', 'web.test.rspcode[api-check,request]'],
            $this->callsTo('item.get')[0]['filter']['key_'],
        );

        $alert = Alert::sole();
        $this->assertSame($server->id, $alert->monitoring_target_id);
        $this->assertSame('cpu', $alert->metric);
        $this->assertEquals(90, $alert->threshold);
        $this->assertNull($alert->value);

        $server->refresh();
        $this->assertSame(TargetStatus::Critical, $server->status);
        $this->assertSame(1791809000, $server->last_checked_at->getTimestamp());
        $this->assertNull($server->last_response_ms);

        $api->refresh();
        $this->assertSame(TargetStatus::Up, $api->status);
        $this->assertEquals(250, $api->last_response_ms);
    }
}
