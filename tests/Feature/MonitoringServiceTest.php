<?php

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Exceptions\Zabbix\ZabbixApiException;
use App\Exceptions\Zabbix\ZabbixConnectionException;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Services\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;
use UnexpectedValueException;

class MonitoringServiceTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeZabbixApi();
    }

    private function createPaymentApi(?Team $team = null, ?User $creator = null): MonitoringTarget
    {
        return app(MonitoringService::class)->createTarget($team ?? Team::factory()->create(), $creator, TargetType::Api, [
            'name' => 'Payment API',
            'url' => 'http://fake-api:8080/health',
            'check_interval' => 30,
            'warning_threshold_ms' => 500,
            'critical_threshold_ms' => 1000,
        ]);
    }

    public function test_it_saves_the_target_with_its_zabbix_host_id(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $target = $this->createPaymentApi($team, $user);

        $this->assertDatabaseHas('monitoring_targets', [
            'id' => $target->id,
            'team_id' => $team->id,
            'created_by' => $user->id,
            'type' => 'api',
            'status' => 'unknown',
            'zabbix_host_id' => '10700',
        ]);
        $this->assertSame(TargetType::Api, $target->type);
        $this->assertSame(TargetStatus::Unknown, $target->fresh()->status);
    }

    public function test_it_creates_the_team_host_group_once_and_remembers_it(): void
    {
        $team = Team::factory()->create(['name' => 'Default']);

        $this->createPaymentApi($team);

        $this->assertSame([['name' => "Monitoring Platform/Default #{$team->id}"]], $this->callsTo('hostgroup.create'));
        $this->assertSame('42', $team->fresh()->zabbix_hostgroup_id);

        // Deuxième target de la même team : le host group est réutilisé sans appel à Zabbix
        $this->calls = [];
        $this->createPaymentApi($team->fresh());

        $this->assertSame([], $this->callsTo('hostgroup.get'));
        $this->assertSame([], $this->callsTo('hostgroup.create'));
        $this->assertSame('42', $this->callsTo('host.create')[0]['groups'][0]['groupid']);
    }

    public function test_it_reuses_an_existing_zabbix_host_group_with_the_same_name(): void
    {
        $team = Team::factory()->create();
        $this->existingGroupId = '77';

        $this->createPaymentApi($team);

        $this->assertSame([], $this->callsTo('hostgroup.create'));
        $this->assertSame('77', $team->fresh()->zabbix_hostgroup_id);
    }

    public function test_it_creates_the_host_in_the_team_group(): void
    {
        $target = $this->createPaymentApi();

        $this->assertSame([[
            'host' => "target-{$target->id}",
            'name' => "Payment API #{$target->id}",
            'groups' => [['groupid' => '42']],
            'tags' => [['tag' => 'target_id', 'value' => (string) $target->id]],
        ]], $this->callsTo('host.create'));
    }

    public function test_it_creates_a_web_scenario_on_the_target_url(): void
    {
        $this->createPaymentApi();

        $scenario = $this->callsTo('httptest.create')[0];
        $this->assertSame('api-check', $scenario['name']);
        $this->assertSame('10700', $scenario['hostid']);
        $this->assertSame('30s', $scenario['delay']);
        $this->assertSame('http://fake-api:8080/health', $scenario['steps'][0]['url']);
        $this->assertSame('200-299', $scenario['steps'][0]['status_codes']);
    }

    public function test_it_creates_unavailable_critical_and_warning_triggers_chained_by_dependencies(): void
    {
        $target = $this->createPaymentApi();
        $host = "target-{$target->id}";

        [$unavailable, $critical, $warning] = $this->callsTo('trigger.create');

        $this->assertSame("last(/$host/web.test.fail[api-check])<>0", $unavailable['expression']);
        $this->assertSame(5, $unavailable['priority']);
        $this->assertSame('Payment API is unavailable', $unavailable['description']);

        $this->assertSame("last(/$host/web.test.time[api-check,request,resp])>1", $critical['expression']);
        $this->assertSame(4, $critical['priority']);
        $this->assertSame([['triggerid' => '500']], $critical['dependencies']);

        $this->assertSame("last(/$host/web.test.time[api-check,request,resp])>0.5", $warning['expression']);
        $this->assertSame(2, $warning['priority']);
        $this->assertSame([['triggerid' => '501']], $warning['dependencies']);

        $this->assertContains(['tag' => 'level', 'value' => 'warning'], $warning['tags']);
        $this->assertContains(['tag' => 'target_id', 'value' => (string) $target->id], $warning['tags']);
    }

    public function test_thresholds_are_converted_to_seconds_without_rounding_errors(): void
    {
        app(MonitoringService::class)->createTarget(Team::factory()->create(), null, TargetType::Api, [
            'name' => 'Slow API', 'url' => 'https://api.example.com', 'check_interval' => 60,
            'warning_threshold_ms' => 1250, 'critical_threshold_ms' => 3000,
        ]);

        [, $critical, $warning] = $this->callsTo('trigger.create');
        $this->assertStringEndsWith('>3', $critical['expression']);
        $this->assertStringEndsWith('>1.25', $warning['expression']);
    }

    public function test_when_a_trigger_fails_the_zabbix_host_is_deleted_and_nothing_is_saved(): void
    {
        $this->failOn('trigger.create', 'Incorrect trigger expression.');

        try {
            $this->createPaymentApi();
            $this->fail('Exception attendue');
        } catch (ZabbixApiException $e) {
            $this->assertStringContainsString('Incorrect trigger expression', $e->getMessage());
        }

        $this->assertSame([['10700']], $this->callsTo('host.delete'));
        $this->assertDatabaseCount('monitoring_targets', 0);
    }

    public function test_when_the_web_scenario_fails_the_zabbix_host_is_deleted(): void
    {
        $this->failOn('httptest.create');

        $this->expectException(ZabbixApiException::class);

        try {
            $this->createPaymentApi();
        } finally {
            $this->assertSame([['10700']], $this->callsTo('host.delete'));
            $this->assertDatabaseCount('monitoring_targets', 0);
        }
    }

    public function test_when_the_host_cannot_be_created_there_is_nothing_to_delete(): void
    {
        $this->failOn('host.create', 'Host with the same name "target-1" already exists.');

        $this->expectException(ZabbixApiException::class);

        try {
            $this->createPaymentApi();
        } finally {
            $this->assertSame([], $this->callsTo('host.delete'));
            $this->assertDatabaseCount('monitoring_targets', 0);
        }
    }

    public function test_when_saving_the_host_id_fails_the_zabbix_host_is_deleted(): void
    {
        MonitoringTarget::updating(fn () => throw new RuntimeException('Database is gone.'));

        $this->expectException(RuntimeException::class);

        try {
            $this->createPaymentApi();
        } finally {
            $this->assertSame([['10700']], $this->callsTo('host.delete'));
            $this->assertDatabaseCount('monitoring_targets', 0);
        }
    }

    public function test_when_the_log_cannot_be_written_the_zabbix_host_is_deleted(): void
    {
        // Vu en vrai (SCRUM-67) : log du jour créé par root, « Permission denied » après la création du host
        Log::listen(function (MessageLogged $log) {
            if ($log->message === 'Monitoring target provisioned in Zabbix') {
                throw new UnexpectedValueException('Permission denied');
            }
        });

        $this->expectException(UnexpectedValueException::class);

        try {
            $this->createPaymentApi();
        } finally {
            $this->assertSame([['10700']], $this->callsTo('host.delete'));
            $this->assertDatabaseCount('monitoring_targets', 0);
        }
    }

    public function test_a_failed_rollback_keeps_the_original_error(): void
    {
        $this->failOn('trigger.create', 'Incorrect trigger expression.');
        $this->failOn('host.delete', 'No permissions.');

        $this->expectException(ZabbixApiException::class);
        $this->expectExceptionMessage('Incorrect trigger expression');

        $this->createPaymentApi();
    }

    public function test_when_zabbix_is_down_no_target_is_saved(): void
    {
        $this->zabbixDown = true;

        $this->expectException(ZabbixConnectionException::class);

        try {
            $this->createPaymentApi();
        } finally {
            $this->assertDatabaseCount('monitoring_targets', 0);
        }
    }

    public function test_deprovision_deletes_the_zabbix_host(): void
    {
        $target = $this->createPaymentApi();

        app(MonitoringService::class)->deprovision($target);

        $this->assertSame([['10700']], $this->callsTo('host.delete'));
        $this->assertNull($target->fresh()->zabbix_host_id);
    }
}
