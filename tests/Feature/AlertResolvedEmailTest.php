<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\TeamRole;
use App\Jobs\SendAlertNotification;
use App\Mail\AlertResolvedMail;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Services\AlertService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Email quand une alerte est résolue (SCRUM-54) : seulement à ceux qui ont été prévenus de son ouverture.
 */
class AlertResolvedEmailTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $viewer;

    private MonitoringTarget $target;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeZabbixApi();
        $this->team = Team::factory()->create(['name' => 'Payments']);
        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $this->viewer = User::factory()->create(['email' => 'viewer@example.com']);
        $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin]);
        $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer]);
        $this->target = MonitoringTarget::factory()->for($this->team)->create(['name' => 'Payment API', 'zabbix_host_id' => '10684']);
        $this->zabbixItems = [
            ['itemid' => '501', 'hostid' => '10684', 'key_' => 'web.test.fail[api-check]', 'lastclock' => '1791809000'],
            ['itemid' => '502', 'hostid' => '10684', 'key_' => 'web.test.time[api-check,request,resp]', 'lastclock' => '1791809000', 'lastvalue' => '0.3'],
        ];
        $this->zabbixHistory = [['clock' => '1791809040', 'value' => '1.25']];
    }

    private function problem(array $overrides = [], string $severity = '4'): array
    {
        return [
            'eventid' => '9001', 'objectid' => '700', 'clock' => '1791809040', 'r_eventid' => '0', 'r_clock' => '0',
            'severity' => $severity, 'name' => 'Payment API response time > 1000 ms', 'acknowledged' => '0',
            'tags' => [
                ['tag' => 'target_id', 'value' => (string) $this->target->id],
                ['tag' => 'metric', 'value' => 'response_time'],
                ['tag' => 'level', 'value' => 'critical'],
            ],
            ...$overrides,
        ];
    }

    private function sync(): void
    {
        app(AlertService::class)->syncFromZabbix();
    }

    /** Ouverture puis résolution de l'alerte par deux synchros */
    private function openThenResolve(string $severity = '4'): void
    {
        $this->zabbixProblems = [$this->problem([], $severity)];
        $this->sync();
        $this->zabbixProblems = [$this->problem(['r_eventid' => '9002', 'r_clock' => '1791809400'], $severity)];
        $this->sync();
    }

    private function resolvedNotifications()
    {
        return AlertNotification::where('event', NotificationEvent::Resolved->value)->orderBy('id')->get();
    }

    public function test_those_notified_of_the_opening_are_notified_of_the_resolution(): void
    {
        $this->openThenResolve();

        $this->assertSame(['admin@example.com', 'viewer@example.com'], $this->resolvedNotifications()->pluck('recipient')->all());
        Queue::assertPushed(SendAlertNotification::class, 4);   // 2 ouvertures, 2 résolutions
    }

    public function test_the_next_syncs_do_not_send_the_resolution_again(): void
    {
        $this->openThenResolve();
        $this->sync();
        $this->sync();

        $this->assertCount(2, $this->resolvedNotifications());
        Queue::assertPushed(SendAlertNotification::class, 4);
    }

    public function test_no_resolution_email_for_an_alert_never_notified(): void
    {
        // WARNING : ouverture au dashboard seulement, mais résolution par email → personne n'a été prévenu
        AlertRule::where(['team_id' => $this->team->id, 'severity' => 'warning', 'event' => 'resolved'])->update(['channel' => NotificationChannel::Email->value]);

        $this->openThenResolve(severity: '2');

        $this->assertSame(AlertStatus::Resolved, Alert::sole()->status);
        $this->assertCount(0, AlertNotification::all());
        Queue::assertNothingPushed();
    }

    public function test_an_alert_discovered_already_resolved_sends_nothing(): void
    {
        $this->zabbixProblems = [$this->problem(['r_eventid' => '9002', 'r_clock' => '1791809400'])];

        $this->sync();
        $this->sync();

        $this->assertCount(0, AlertNotification::all());
    }

    public function test_a_failed_opening_email_means_no_resolution_email_for_that_member(): void
    {
        $this->zabbixProblems = [$this->problem()];
        $this->sync();
        AlertNotification::where('recipient', 'viewer@example.com')->update(['status' => NotificationStatus::Failed->value]);
        $this->zabbixProblems = [$this->problem(['r_eventid' => '9002', 'r_clock' => '1791809400'])];

        $this->sync();

        $this->assertSame(['admin@example.com'], $this->resolvedNotifications()->pluck('recipient')->all());
    }

    public function test_a_member_who_left_the_team_is_not_told(): void
    {
        $this->zabbixProblems = [$this->problem()];
        $this->sync();
        $this->team->members()->detach($this->viewer);
        $this->zabbixProblems = [$this->problem(['r_eventid' => '9002', 'r_clock' => '1791809400'])];

        $this->sync();

        $this->assertSame(['admin@example.com'], $this->resolvedNotifications()->pluck('recipient')->all());
    }

    public function test_a_dashboard_only_resolution_rule_sends_nothing(): void
    {
        AlertRule::where(['team_id' => $this->team->id, 'severity' => 'high', 'event' => 'resolved'])->update(['channel' => NotificationChannel::Dashboard->value]);

        $this->openThenResolve();

        $this->assertCount(0, $this->resolvedNotifications());
    }

    public function test_an_alert_resolved_after_vanishing_from_zabbix_is_announced_too(): void
    {
        $this->zabbixProblems = [$this->problem()];
        $this->sync();
        // Plus dans problem.get : la synchro lit son événement de résolution
        $this->zabbixProblems = [];
        $this->zabbixEvents = [
            ['eventid' => '9001', 'r_eventid' => '9002'],
            ['eventid' => '9002', 'clock' => '1791809400'],
        ];

        $this->sync();

        $this->assertSame(AlertStatus::Resolved, Alert::sole()->status);
        $this->assertCount(2, $this->resolvedNotifications());
    }

    public function test_the_job_sends_the_resolution_email_with_the_maximum_of_the_incident(): void
    {
        Mail::fake();
        $this->openThenResolve();
        // Mesures pendant l'incident : maximum 1,8 s
        $this->zabbixHistory = [['clock' => '1', 'value' => '0.9'], ['clock' => '2', 'value' => '1.8']];
        $notification = $this->resolvedNotifications()->first();

        (new SendAlertNotification($notification->id))->handle();

        Mail::assertSent(AlertResolvedMail::class, fn (AlertResolvedMail $mail) => $mail->hasTo('admin@example.com') && $mail->peak === 1800.0);
        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
        $this->assertSame(1800.0, Alert::sole()->peak_value);    // gardée pour l'onglet History
    }

    public function test_the_resolution_email_gives_target_severity_start_end_duration_and_maximum(): void
    {
        $alert = Alert::factory()->for($this->target, 'target')->create([
            'severity' => AlertSeverity::High, 'status' => AlertStatus::Resolved, 'message' => 'Payment API response time > 1000 ms',
            'metric' => 'response_time', 'value' => 1250, 'threshold' => 1000,
            'started_at' => CarbonImmutable::parse('2026-10-24 13:05:00', 'UTC'), 'resolved_at' => CarbonImmutable::parse('2026-10-24 13:17:30', 'UTC'),
        ]);
        $mail = new AlertResolvedMail($alert, 1800.0);

        $mail->assertHasSubject('[RESOLVED] Payment API response time > 1000 ms');
        $mail->assertSeeInOrderInHtml(['Resolved: Payment API', 'Target', 'Payment API', 'Team', 'Payments', 'Severity', 'HIGH',
            'Started', '24/10/2026 15:05:00', 'Resolved', '24/10/2026 15:17:30', 'Duration', '12m 30s', 'Maximum', '1,800 ms', 'Threshold', '&gt; 1,000 ms'], false);
        $mail->assertSeeInHtml(route('targets.show', [$this->target, 'tab' => 'history']), false);
    }

    public function test_an_unknown_maximum_is_shown_as_a_dash(): void
    {
        $alert = Alert::factory()->for($this->target, 'target')->create([
            'status' => AlertStatus::Resolved, 'metric' => 'availability', 'value' => null, 'threshold' => null, 'resolved_at' => now(),
        ]);

        (new AlertResolvedMail($alert))->assertSeeInOrderInHtml(['Maximum', '—']);
    }
}
