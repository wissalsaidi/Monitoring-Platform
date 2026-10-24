<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\NotificationStatus;
use App\Enums\TeamRole;
use App\Jobs\SendAlertNotification;
use App\Mail\AlertOpenedMail;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Services\AlertService;
use App\Services\NotificationRouter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Email quand une alerte s'ouvre (SCRUM-53) : créé par la synchro, envoyé par la queue, nouvelles tentatives puis échec.
 */
class AlertOpenedEmailTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private Team $team;

    private MonitoringTarget $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeZabbixApi();
        $this->team = Team::factory()->create(['name' => 'Payments']);
        $this->team->members()->attach(User::factory()->create(['email' => 'admin@example.com']), ['role' => TeamRole::Admin]);
        $this->team->members()->attach(User::factory()->create(['email' => 'viewer@example.com']), ['role' => TeamRole::Viewer]);
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

    private function sync(): array
    {
        return app(AlertService::class)->syncFromZabbix();
    }

    // ─── Synchro → notifications en queue ───

    public function test_a_new_high_alert_queues_one_email_per_member(): void
    {
        Queue::fake();
        $this->zabbixProblems = [$this->problem()];

        $this->sync();

        $notifications = AlertNotification::orderBy('id')->get();
        $this->assertSame(['admin@example.com', 'viewer@example.com'], $notifications->pluck('recipient')->all());
        $this->assertTrue($notifications->every(fn ($n) => $n->status === NotificationStatus::Pending));
        Queue::assertPushed(SendAlertNotification::class, 2);
        Queue::assertPushed(SendAlertNotification::class, fn ($job) => $job->notificationId === $notifications[0]->id);
    }

    public function test_the_sync_does_not_send_the_email_itself(): void
    {
        Queue::fake();
        Mail::fake();
        $this->zabbixProblems = [$this->problem()];

        $this->sync();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_a_warning_alert_stays_on_the_dashboard(): void
    {
        Queue::fake();
        $this->zabbixProblems = [$this->problem(['name' => 'Payment API response time > 500 ms'], severity: '2')];

        $this->sync();

        $this->assertSame(1, Alert::count());
        $this->assertSame(0, AlertNotification::count());
        Queue::assertNothingPushed();
    }

    public function test_an_alert_already_resolved_when_discovered_is_not_announced(): void
    {
        Queue::fake();
        $this->zabbixProblems = [$this->problem(['r_eventid' => '9002', 'r_clock' => '1791809100'])];

        $this->sync();

        Queue::assertNothingPushed();
    }

    public function test_the_next_syncs_do_not_send_it_again(): void
    {
        Queue::fake();
        $this->zabbixProblems = [$this->problem()];

        $this->sync();
        $this->sync();
        $this->sync();

        Queue::assertPushed(SendAlertNotification::class, 2);
        $this->assertSame(2, AlertNotification::count());
    }

    public function test_a_notification_error_never_breaks_the_sync(): void
    {
        Queue::fake();
        $this->mock(NotificationRouter::class)->shouldReceive('plan')->andThrow(new RuntimeException('Database is gone'));
        $this->zabbixProblems = [$this->problem()];

        $stats = $this->sync();

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, Alert::count());
    }

    // ─── Envoi ───

    public function test_the_job_sends_the_email_and_marks_it_sent(): void
    {
        Mail::fake();
        Queue::fake();
        $this->zabbixProblems = [$this->problem()];
        $this->sync();
        $notification = AlertNotification::orderBy('id')->first();

        (new SendAlertNotification($notification->id))->handle();

        Mail::assertSent(AlertOpenedMail::class, fn (AlertOpenedMail $mail) => $mail->hasTo('admin@example.com'));
        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertNotNull($notification->sent_at);
        $this->assertSame(1, $notification->attempts);
    }

    public function test_a_sent_notification_is_not_sent_again(): void
    {
        Mail::fake();
        Queue::fake();
        $this->zabbixProblems = [$this->problem()];
        $this->sync();
        $notification = AlertNotification::orderBy('id')->first();

        (new SendAlertNotification($notification->id))->handle();
        (new SendAlertNotification($notification->id))->handle();

        Mail::assertSentCount(1);
    }

    public function test_the_email_gives_severity_target_metric_value_threshold_start_and_link(): void
    {
        $alert = Alert::factory()->for($this->target, 'target')->create([
            'severity' => AlertSeverity::High, 'message' => 'Payment API response time > 1000 ms', 'metric' => 'response_time',
            'value' => 1250, 'threshold' => 1000, 'started_at' => CarbonImmutable::parse('2026-10-24 13:05:00', 'UTC'),
        ]);
        $mail = new AlertOpenedMail($alert);

        $mail->assertHasSubject('[HIGH] Payment API response time > 1000 ms');
        $mail->assertSeeInOrderInHtml(['HIGH alert on Payment API', 'Severity', 'HIGH', 'Target', 'Payment API', 'Team', 'Payments',
            'Metric', 'Response time', 'Measured value', '1,250 ms', 'Threshold', '&gt; 1,000 ms', 'Started', '24/10/2026 15:05:00'], false);
        $mail->assertSeeInHtml(route('targets.show', [$this->target, 'tab' => 'alerts']), false);
        $this->assertNotInstanceOf(ShouldQueue::class, $mail);    // le job attend le résultat de l'envoi
    }

    public function test_an_smtp_failure_is_retried_then_marked_failed_and_logged_without_the_email_address(): void
    {
        Queue::fake();
        $this->zabbixProblems = [$this->problem()];
        $this->sync();
        $notification = AlertNotification::orderBy('id')->first();
        Mail::shouldReceive('to->send')->andThrow(new TransportException('Connection to "mailpit:1025" refused'));
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $job = new SendAlertNotification($notification->id);

        // Tentatives : l'exception repart vers la queue, qui recommencera
        try {
            $job->handle();
            $this->fail('The job should let the queue retry.');
        } catch (TransportException $e) {
            $job->failed($e);
        }

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120], $job->backoff);
        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertStringContainsString('refused', $notification->error);
        $this->assertTrue(collect($logged)->contains(fn ($line) => str_contains($line, 'Alert notification failed')));
        $this->assertFalse(collect($logged)->contains(fn ($line) => str_contains($line, 'admin@example.com')));
    }

    public function test_a_deleted_alert_is_skipped(): void
    {
        Mail::fake();
        Queue::fake();
        $this->zabbixProblems = [$this->problem()];
        $this->sync();
        $notification = AlertNotification::orderBy('id')->first();
        Alert::query()->delete();

        (new SendAlertNotification($notification->id))->handle();

        Mail::assertNothingSent();
    }
}
