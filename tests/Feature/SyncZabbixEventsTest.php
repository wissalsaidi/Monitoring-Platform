<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\TargetStatus;
use App\Jobs\SyncZabbixEvents;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Services\AlertService;
use App\Services\ZabbixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class SyncZabbixEventsTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private MonitoringTarget $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeZabbixApi();
        $this->target = MonitoringTarget::factory()->create(['zabbix_host_id' => '10684']);
        $this->zabbixItems = $this->checkItems('10684', lastclock: '1791809000');
    }

    private function checkItems(string $hostId, string $lastclock): array
    {
        return [
            ['itemid' => '501', 'hostid' => $hostId, 'key_' => 'web.test.fail[api-check]', 'lastclock' => $lastclock],
            ['itemid' => '502', 'hostid' => $hostId, 'key_' => 'web.test.time[api-check,request,resp]', 'lastclock' => $lastclock],
        ];
    }

    private function problem(array $overrides = [], string $metric = 'response_time', string $level = 'critical'): array
    {
        return [
            'eventid' => '9001',
            'objectid' => '700',
            'clock' => '1791809040',
            'r_eventid' => '0',
            'r_clock' => '0',
            'severity' => '4',
            'name' => 'Payment API response time > 1000 ms',
            'acknowledged' => '0',
            'tags' => [
                ['tag' => 'target_id', 'value' => (string) $this->target->id],
                ['tag' => 'metric', 'value' => $metric],
                ['tag' => 'level', 'value' => $level],
            ],
            ...$overrides,
        ];
    }

    private function sync(): array
    {
        return app(AlertService::class)->syncFromZabbix();
    }

    public function test_a_new_problem_creates_an_alert_and_degrades_the_target(): void
    {
        $this->zabbixProblems = [$this->problem()];
        $this->zabbixHistory = [['clock' => '1791809040', 'value' => '1.24']];

        $stats = $this->sync();

        $alert = Alert::sole();
        $this->assertSame($this->target->id, $alert->monitoring_target_id);
        $this->assertSame('9001', $alert->zabbix_event_id);
        $this->assertSame('700', $alert->zabbix_trigger_id);
        $this->assertSame(AlertSeverity::High, $alert->severity);
        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertSame('Payment API response time > 1000 ms', $alert->message);
        $this->assertSame('response_time', $alert->metric);
        $this->assertSame(1240.0, $alert->value);
        $this->assertSame(1000, $alert->threshold);
        $this->assertSame(1791809040, $alert->started_at->timestamp);
        $this->assertNull($alert->resolved_at);

        $this->assertSame(TargetStatus::Critical, $this->target->fresh()->status);
        $this->assertSame(['problems' => 1, 'created' => 1, 'resolved' => 0, 'targets' => 1], $stats);
    }

    public function test_it_reads_the_response_time_at_the_time_of_the_problem(): void
    {
        $this->zabbixProblems = [$this->problem()];

        $this->sync();

        $this->assertSame([[
            'output' => ['clock', 'value'],
            'itemids' => ['502'],
            'history' => 0,
            'time_till' => 1791809040,
            'sortfield' => 'clock',
            'sortorder' => 'DESC',
            'limit' => 1,
        ]], $this->callsTo('history.get'));
    }

    public function test_it_only_asks_zabbix_for_the_platform_hosts(): void
    {
        $this->sync();

        $this->assertSame(['10684'], $this->callsTo('problem.get')[0]['hostids']);
        $this->assertTrue($this->callsTo('problem.get')[0]['recent']);
    }

    public function test_running_the_sync_twice_does_not_duplicate_alerts(): void
    {
        $this->zabbixProblems = [$this->problem()];

        $this->sync();
        $stats = $this->sync();

        $this->assertSame(1, Alert::count());
        $this->assertSame(0, $stats['created']);
    }

    public function test_a_warning_problem_sets_the_target_to_warning(): void
    {
        $this->zabbixProblems = [$this->problem(['severity' => '2'], level: 'warning')];

        $this->sync();

        $this->assertSame(500, Alert::sole()->threshold);
        $this->assertSame(TargetStatus::Warning, $this->target->fresh()->status);
    }

    public function test_an_unavailable_target_is_critical_without_response_time(): void
    {
        $this->zabbixProblems = [$this->problem(['severity' => '5', 'name' => 'Payment API is unavailable'], 'availability', 'unavailable')];

        $this->sync();

        $alert = Alert::sole();
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertNull($alert->value);
        $this->assertNull($alert->threshold);
        $this->assertSame([], $this->callsTo('history.get'));
        $this->assertSame(TargetStatus::Critical, $this->target->fresh()->status);
    }

    public function test_a_resolved_problem_resolves_the_alert_and_the_target_is_up_again(): void
    {
        $this->zabbixProblems = [$this->problem()];
        $this->sync();

        $this->zabbixProblems = [$this->problem(['r_eventid' => '9002', 'r_clock' => '1791809760'])];
        $stats = $this->sync();

        $alert = Alert::sole();
        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertSame(1791809760, $alert->resolved_at->timestamp);
        $this->assertSame(1, $stats['resolved']);
        $this->assertSame(TargetStatus::Up, $this->target->fresh()->status);
    }

    public function test_an_alert_no_longer_listed_by_zabbix_is_resolved_from_its_recovery_event(): void
    {
        $alert = Alert::factory()->for($this->target, 'target')->create(['zabbix_event_id' => '9001']);
        $this->zabbixEvents = [
            ['eventid' => '9001', 'r_eventid' => '9002'],
            ['eventid' => '9002', 'clock' => '1791809760'],
        ];

        $stats = $this->sync();

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
        $this->assertSame(1791809760, $alert->fresh()->resolved_at->timestamp);
        $this->assertSame(1, $stats['resolved']);
    }

    public function test_an_alert_whose_event_is_gone_is_resolved_now(): void
    {
        $this->freezeSecond();
        $alert = Alert::factory()->for($this->target, 'target')->create(['zabbix_event_id' => '9001']);

        $this->sync();

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
        $this->assertTrue($alert->fresh()->resolved_at->equalTo(now()));
    }

    public function test_an_alert_still_open_in_zabbix_stays_open(): void
    {
        // Problème masqué par une dépendance de trigger : absent de problem.get mais pas résolu.
        $alert = Alert::factory()->for($this->target, 'target')->create(['zabbix_event_id' => '9001']);
        $this->zabbixEvents = [['eventid' => '9001', 'r_eventid' => '0']];

        $this->sync();

        $this->assertSame(AlertStatus::Open, $alert->fresh()->status);
    }

    public function test_a_problem_acknowledged_in_zabbix_acknowledges_the_alert(): void
    {
        $this->zabbixProblems = [$this->problem(['acknowledged' => '1'])];

        $this->sync();

        $alert = Alert::sole();
        $this->assertSame(AlertStatus::Acknowledged, $alert->status);
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertSame(TargetStatus::Critical, $this->target->fresh()->status);
    }

    public function test_the_worst_active_alert_gives_the_target_status(): void
    {
        $this->zabbixProblems = [
            $this->problem(['eventid' => '1', 'severity' => '2'], level: 'warning'),
            $this->problem(['eventid' => '2', 'severity' => '4']),
        ];

        $this->sync();

        $this->assertSame(TargetStatus::Critical, $this->target->fresh()->status);
    }

    public function test_a_target_never_checked_by_zabbix_stays_unknown(): void
    {
        $this->zabbixItems = $this->checkItems('10684', lastclock: '0');

        $this->sync();

        $this->assertSame(TargetStatus::Unknown, $this->target->fresh()->status);
    }

    public function test_a_checked_target_without_problem_is_up(): void
    {
        $this->sync();

        $this->assertSame(TargetStatus::Up, $this->target->fresh()->status);
    }

    public function test_problems_of_unknown_targets_are_ignored(): void
    {
        $this->zabbixProblems = [$this->problem(['tags' => [['tag' => 'target_id', 'value' => '999']]])];

        $this->sync();

        $this->assertSame(0, Alert::count());
    }

    public function test_it_does_not_call_zabbix_without_provisioned_targets(): void
    {
        $this->target->update(['zabbix_host_id' => null]);

        $this->sync();

        $this->assertSame([], $this->calls);
    }

    public function test_the_job_logs_the_synchronization(): void
    {
        $this->zabbixProblems = [$this->problem()];
        $log = $this->spyZabbixLog();

        SyncZabbixEvents::dispatchSync();

        $this->assertSame(1, Alert::count());
        $log->shouldHaveReceived('info')->with('Zabbix synchronization started')->once();
        $log->shouldHaveReceived('info')->with('1 events retrieved', \Mockery::type('array'))->once();
    }

    public function test_when_zabbix_is_down_the_job_logs_a_critical_error_and_keeps_the_statuses(): void
    {
        $this->target->update(['status' => TargetStatus::Warning]);
        $this->zabbixDown = true;
        $log = $this->spyZabbixLog();

        SyncZabbixEvents::dispatchSync();

        $this->assertSame(TargetStatus::Warning, $this->target->fresh()->status);
        $log->shouldHaveReceived('critical')->with('Monitoring synchronization failed', \Mockery::type('array'))->once();
    }

    /** Remplace le canal de logs "zabbix" par un espion (à appeler avant de résoudre les services). */
    private function spyZabbixLog(): MockInterface
    {
        $spy = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('zabbix')->andReturn($spy);
        $this->app->forgetInstance(ZabbixService::class);

        return $spy;
    }

    public function test_the_job_is_scheduled_every_minute(): void
    {
        Queue::fake();

        $this->artisan('schedule:run')->assertSuccessful();

        Queue::assertPushed(SyncZabbixEvents::class);
    }

    public function test_the_sync_command_prints_a_summary(): void
    {
        $this->zabbixProblems = [$this->problem()];

        $this->artisan('zabbix:sync')
            ->expectsTable(['Problems', 'New alerts', 'Resolved', 'Target statuses changed'], [[1, 1, 0, 1]])
            ->assertSuccessful();
    }

    public function test_the_sync_command_shows_a_clean_error_when_zabbix_is_down(): void
    {
        $this->zabbixDown = true;

        $this->artisan('zabbix:sync')
            ->expectsOutput('Monitoring service temporarily unavailable.')
            ->assertFailed();
    }
}
