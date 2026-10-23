<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\Provisioners\DatabaseProvisioner;
use App\Services\AlertService;
use App\Services\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Base de données MySQL (SCRUM-71) : mot de passe chiffré, jamais affiché ni journalisé.
 */
class DatabaseTargetTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private const PASSWORD = 'S3cret-Pa55word!';

    private const SETTINGS = ['address' => 'db.internal', 'port' => 3306, 'username' => 'zbx_monitor', 'connections_warning' => 150];

    private const DATABASE = ['name' => 'Orders DB', 'check_interval' => 60, 'settings' => self::SETTINGS, 'credentials' => ['password' => self::PASSWORD]];

    private const ALL_TRIGGERS = [
        ['triggerid' => '500', 'tags' => [['tag' => 'level', 'value' => 'unreachable']]],
        ['triggerid' => '501', 'tags' => [['tag' => 'level', 'value' => 'connections_warning']]],
    ];

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->team = Team::factory()->create(['zabbix_hostgroup_id' => '42']);
        $this->zabbixHosts = [['hostid' => '10700', 'interfaces' => [['interfaceid' => '90', 'type' => '1']]]];
    }

    private function member(TeamRole $role = TeamRole::Manager): User
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private function existingDatabase(array $attributes = []): MonitoringTarget
    {
        return MonitoringTarget::factory()->for($this->team)->create([
            'type' => TargetType::Database, 'url' => null, 'warning_threshold_ms' => null, 'critical_threshold_ms' => null,
            ...self::DATABASE, 'zabbix_host_id' => '10700', ...$attributes,
        ]);
    }

    /** Tous les paramètres envoyés à Zabbix, sauf la macro secrète elle-même */
    private function zabbixTrafficWithoutTheSecretMacro(): string
    {
        $calls = array_map(function (array $call) {
            if (isset($call['params']['macros'])) {
                $call['params']['macros'] = array_values(array_filter($call['params']['macros'], fn ($m) => $m['macro'] !== '{$MYSQL.PASSWORD}'));
            }

            return $call;
        }, $this->calls);

        return json_encode($calls);
    }

    public function test_a_manager_creates_a_database_and_the_password_is_stored_encrypted(): void
    {
        $this->actingAs($this->member())->post(route('targets.store'), ['type' => 'database', ...self::DATABASE])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $target = MonitoringTarget::sole();
        $this->assertSame(TargetType::Database, $target->type);
        $this->assertSame(self::PASSWORD, $target->credential('password'));
        $this->assertArrayNotHasKey('password', $target->settings);

        // En base : chiffré, jamais en clair
        $raw = DB::table('monitoring_targets')->value('credentials');
        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString(self::PASSWORD, $raw);

        // Ni sérialisé, ni dans l'audit de création
        $this->assertStringNotContainsString(self::PASSWORD, $target->toJson());
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(AuditLog::sole()->details));
    }

    public function test_the_password_reaches_zabbix_only_as_a_secret_macro(): void
    {
        app(MonitoringService::class)->createTarget($this->team, null, TargetType::Database, self::DATABASE);

        $macros = collect($this->callsTo('host.create')[0]['macros'])->keyBy('macro');
        $this->assertSame(['value' => self::PASSWORD, 'type' => 1], [
            'value' => $macros['{$MYSQL.PASSWORD}']['value'], 'type' => $macros['{$MYSQL.PASSWORD}']['type'],
        ]);
        $this->assertSame('tcp://db.internal:3306', $macros['{$MYSQL.DSN}']['value']);
        $this->assertSame('zbx_monitor', $macros['{$MYSQL.USER}']['value']);

        // Nulle part ailleurs : ni dans les clés d'items, ni dans les triggers
        $this->assertStringNotContainsString(self::PASSWORD, $this->zabbixTrafficWithoutTheSecretMacro());
    }

    public function test_an_ipv6_host_is_bracketed_in_the_dsn(): void
    {
        app(MonitoringService::class)->createTarget($this->team, null, TargetType::Database,
            [...self::DATABASE, 'settings' => [...self::SETTINGS, 'address' => '2001:db8::10']]);

        $dsn = collect($this->callsTo('host.create')[0]['macros'])->firstWhere('macro', '{$MYSQL.DSN}')['value'];
        $this->assertSame('tcp://[2001:db8::10]:3306', $dsn);
    }

    public function test_the_items_and_triggers_are_created(): void
    {
        app(MonitoringService::class)->createTarget($this->team, null, TargetType::Database, self::DATABASE);

        [$agentItems, $status, $dependents] = $this->callsTo('item.create');
        $keys = array_column($agentItems, 'key_');
        $this->assertSame([DatabaseProvisioner::PING_KEY, DatabaseProvisioner::VERSION_KEY, DatabaseProvisioner::RESPONSE_KEY], $keys);
        $this->assertSame(3, $agentItems[2]['type']);     // check simple, mesuré par zabbix-server
        $this->assertSame(DatabaseProvisioner::STATUS_KEY, $status['key_']);
        $this->assertSame('0', $status['history']);
        $this->assertSame('$.Threads_connected', collect($dependents)->firstWhere('key_', 'mysql.connections')['preprocessing'][0]['params']);

        $triggers = collect($this->callsTo('trigger.create'))->keyBy(fn ($t) => collect($t['tags'])->firstWhere('tag', 'level')['value']);
        $ping = '/target-1/'.DatabaseProvisioner::PING_KEY;
        $this->assertSame("last($ping)=0 or nodata($ping,180s)=1", $triggers['unreachable']['expression']);
        $this->assertSame(5, $triggers['unreachable']['priority']);        // CRITICAL
        $this->assertSame('avg(/target-1/mysql.connections,5m)>150', $triggers['connections_warning']['expression']);
        $this->assertSame(2, $triggers['connections_warning']['priority']); // WARNING
    }

    public function test_fields_are_validated_and_the_password_is_required_at_creation(): void
    {
        $this->actingAs($this->member())->post(route('targets.store'), [
            'type' => 'database', 'name' => 'DB', 'check_interval' => 60,
            'settings' => [...self::SETTINGS, 'username' => 'bad user"'],
        ])->assertSessionHasErrors(['settings.username', 'credentials.password']);

        $this->assertDatabaseCount('monitoring_targets', 0);
    }

    public function test_the_password_is_never_flashed_back_after_an_error(): void
    {
        $manager = $this->member();

        // Erreur de validation
        $this->actingAs($manager)->from(route('targets.create'))->post(route('targets.store'), [
            ...self::DATABASE, 'type' => 'database', 'name' => '',
        ])->assertSessionHasErrors('name');
        $this->assertNull(session()->getOldInput('credentials'));

        // Erreur Zabbix
        $this->failOn('host.create');
        $this->actingAs($manager)->from(route('targets.create'))->post(route('targets.store'), [...self::DATABASE, 'type' => 'database'])
            ->assertSessionHas('error');
        $this->assertNull(session()->getOldInput('credentials'));
        $this->assertSame('Orders DB', session()->getOldInput('name'));
    }

    public function test_the_edit_form_never_shows_the_password(): void
    {
        $target = $this->existingDatabase();

        $this->actingAs($this->member())->get(route('targets.edit', $target))
            ->assertOk()
            ->assertSee('Leave empty to keep the current password')
            ->assertDontSee(self::PASSWORD);
    }

    public function test_an_empty_password_keeps_the_current_one(): void
    {
        $target = $this->existingDatabase();
        $this->zabbixItems = [['itemid' => '1'], ['itemid' => '2'], ['itemid' => '3']];
        $this->zabbixTriggers = self::ALL_TRIGGERS;

        $this->actingAs($this->member())->put(route('targets.update', $target), [
            'name' => 'Orders DB', 'check_interval' => 60,
            'settings' => [...self::SETTINGS, 'connections_warning' => 200], 'credentials' => ['password' => ''],
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::PASSWORD, $target->fresh()->credential('password'));
        $macros = collect(collect($this->callsTo('host.update'))->last()['macros'])->keyBy('macro');
        $this->assertSame(self::PASSWORD, $macros['{$MYSQL.PASSWORD}']['value']);
        $this->assertSame('avg(/target-1/mysql.connections,5m)>200', collect($this->callsTo('trigger.update')[0])->firstWhere('triggerid', '501')['expression']);
    }

    public function test_a_new_password_is_sent_to_zabbix_and_hidden_in_the_audit(): void
    {
        $target = $this->existingDatabase();
        $this->zabbixItems = [['itemid' => '1'], ['itemid' => '2'], ['itemid' => '3']];
        $this->zabbixTriggers = self::ALL_TRIGGERS;

        $this->actingAs($this->member())->put(route('targets.update', $target), [
            'name' => 'Orders DB', 'check_interval' => 60, 'settings' => self::SETTINGS, 'credentials' => ['password' => 'N3w-Secret'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('N3w-Secret', $target->fresh()->credential('password'));
        $macros = collect(collect($this->callsTo('host.update'))->last()['macros'])->keyBy('macro');
        $this->assertSame('N3w-Secret', $macros['{$MYSQL.PASSWORD}']['value']);

        $details = AuditLog::where('action', 'target.update')->sole()->details;
        $this->assertSame(['(hidden)', '(hidden)'], $details['changes']['credentials']);
        $this->assertStringNotContainsString('N3w-Secret', json_encode($details));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($details));
    }

    public function test_the_sync_records_alerts_and_metrics(): void
    {
        $target = $this->existingDatabase();
        $item = fn (string $key, string $value) => ['itemid' => (string) crc32($key), 'hostid' => '10700', 'key_' => $key, 'lastclock' => '1791809000', 'lastvalue' => $value];
        $this->zabbixItems = [
            $item(DatabaseProvisioner::PING_KEY, '1'),
            $item(DatabaseProvisioner::RESPONSE_KEY, '0.0042'),
            $item(DatabaseProvisioner::VERSION_KEY, '8.0.46'),
            $item('mysql.connections', '180'),
            $item('mysql.slow_queries', '12'),
            $item('mysql.slow_queries.rate', '0.5'),
            $item('mysql.queries.rate', '35.2'),
            $item('mysql.uptime', '86400'),
        ];
        $this->zabbixProblems = [[
            'eventid' => '9500', 'objectid' => '501', 'clock' => '1791809040', 'r_eventid' => '0', 'r_clock' => '0',
            'severity' => '2', 'name' => 'Orders DB has more than 150 connections', 'acknowledged' => '0',
            'tags' => [['tag' => 'target_id', 'value' => (string) $target->id], ['tag' => 'metric', 'value' => 'connections'], ['tag' => 'level', 'value' => 'connections_warning']],
        ]];

        app(AlertService::class)->syncFromZabbix();

        $alert = Alert::sole();
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertSame('> 150 connections', $alert->thresholdLabel());

        $target->refresh();
        $this->assertSame(4.2, $target->last_response_ms);
        $this->assertSame(['connections' => 180, 'slow_queries' => 12, 'slow_queries_per_min' => 0.5, 'queries_per_sec' => 35.2, 'uptime' => 86400, 'version' => '8.0.46'], $target->last_metrics);
    }

    public function test_the_overview_shows_the_database_without_its_password(): void
    {
        $target = $this->existingDatabase([
            'last_response_ms' => 4.2,
            'last_metrics' => ['connections' => 180, 'slow_queries' => 12, 'slow_queries_per_min' => 0.5, 'queries_per_sec' => 35.2, 'uptime' => 86400, 'version' => '8.0.46'],
        ]);
        $viewer = $this->member(TeamRole::Viewer);

        $this->actingAs($viewer)->get(route('targets.show', $target))
            ->assertOk()
            ->assertSeeInOrder(['Last response time', '4', 'MySQL', '8.0.46', 'Host', 'db.internal:3306', 'User zbx_monitor'])
            ->assertSeeInOrder(['Connections', '180', 'Warning &gt; 150', 'Queries', '35.20', 'Slow queries', '0.5', '12 since startup', 'Uptime', '1d'], false)
            ->assertSee('text-warning">180', false)
            ->assertDontSee('Warning above')         // pas de seuils en ms pour une base
            ->assertDontSee(self::PASSWORD);

        $this->actingAs($viewer)->get(route('targets.show', [$target, 'tab' => 'configuration']))
            ->assertOk()
            ->assertSee(['db.internal:3306', 'user &quot;zbx_monitor&quot;'], false)
            ->assertDontSee(self::PASSWORD);
    }

    public function test_the_response_time_chart_has_no_thresholds(): void
    {
        $target = $this->existingDatabase();

        $this->actingAs($this->member(TeamRole::Viewer))->getJson(route('targets.metrics.response-time', $target))
            ->assertOk()
            ->assertJsonPath('thresholds', ['warning' => null, 'critical' => null]);

        $this->assertSame([DatabaseProvisioner::RESPONSE_KEY], $this->callsTo('item.get')[0]['filter']['key_']);
    }
}
