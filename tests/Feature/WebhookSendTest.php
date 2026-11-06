<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\WebhookType;
use App\Exceptions\WebhookDeliveryException;
use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\Webhook;
use App\Services\IncidentHistoryService;
use App\Services\WebhookPayload;
use App\Services\WebhookSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesDns;
use Tests\TestCase;

/**
 * Envoi signé d'un webhook générique (SCRUM-76).
 */
class WebhookSendTest extends TestCase
{
    use FakesDns, \Illuminate\Foundation\Testing\RefreshDatabase;

    private const URL = 'https://itsm.example.com/hooks/monitoring?token=very-secret-token';

    private Webhook $webhook;

    private Alert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDns();
        config(['app.url' => 'https://monitoring.company.com']);
        $team = Team::factory()->create(['name' => 'Payments']);
        $target = MonitoringTarget::factory()->for($team)->create(['name' => 'Website-01']);
        $this->alert = Alert::factory()->for($target, 'target')->create([
            'severity' => AlertSeverity::High, 'metric' => 'response_time', 'value' => 2504.5, 'threshold' => 2000,
            'message' => 'Response time > 2000 ms', 'started_at' => '2026-11-07 09:00:00',
        ]);
        $this->webhook = Webhook::factory()->for($team)->create(['name' => 'ITSM', 'type' => WebhookType::Generic, 'url' => self::URL]);
    }

    private function notification(NotificationEvent $event = NotificationEvent::Opened): AlertNotification
    {
        return AlertNotification::create([
            'alert_id' => $this->alert->id, 'webhook_id' => $this->webhook->id, 'recipient' => 'ITSM',
            'channel' => NotificationChannel::Webhook, 'event' => $event,
        ]);
    }

    private function deliver(AlertNotification $notification): void
    {
        (new SendAlertNotification($notification->id))->handle();
    }

    // ─── Message ────────────────────────────────────────────────────────

    public function test_a_signed_versioned_json_post(): void
    {
        Http::fake(['itsm.example.com/*' => Http::response(['ok' => true], 202)]);
        $this->travelTo('2026-11-07 09:01:30');
        $notification = $this->notification();

        $this->deliver($notification);

        Http::assertSent(function (Request $request) use ($notification) {
            $body = $request->body();
            $payload = json_decode($body, true);
            $timestamp = $request->header('X-Platform-Timestamp')[0];

            $this->assertSame('POST', $request->method());
            $this->assertSame(self::URL, $request->url());
            $this->assertSame('application/json', $request->header('Content-Type')[0]);
            $this->assertSame('MonitoringPlatform-Webhook/1', $request->header('User-Agent')[0]);
            $this->assertSame('alert.opened', $request->header('X-Platform-Event')[0]);
            $this->assertSame(WebhookPayload::deliveryId($notification->id), $request->header('X-Platform-Delivery')[0]);
            $this->assertSame((string) now()->getTimestamp(), $timestamp);
            // Vérification telle que le receveur la fait (README)
            $this->assertTrue(hash_equals(
                'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->webhook->secret),
                $request->header('X-Platform-Signature')[0],
            ));

            $this->assertSame(1, $payload['version']);
            $this->assertSame('alert.opened', $payload['event']);
            $this->assertSame(WebhookPayload::deliveryId($notification->id), $payload['delivery_id']);
            $this->assertSame('2026-11-07T09:01:30Z', $payload['sent_at']);
            $this->assertSame([
                'id' => $this->alert->id, 'severity' => 'high', 'status' => 'open', 'message' => 'Response time > 2000 ms',
                'metric' => 'response_time', 'value' => 2504.5, 'threshold' => 2000, 'peak_value' => null,
                'started_at' => '2026-11-07T09:00:00Z', 'resolved_at' => null,
                'url' => route('targets.show', [$this->alert->target, 'tab' => 'alerts']),
            ], $payload['alert']);
            $this->assertSame('Website-01', $payload['target']['name']);
            $this->assertSame(['id' => $this->alert->target->team_id, 'name' => 'Payments'], $payload['team']);

            return true;
        });

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertNotNull($notification->sent_at);
    }

    public function test_the_resolution_carries_the_end_and_the_peak_value(): void
    {
        Http::fake(['itsm.example.com/*' => Http::response('', 204)]);
        $this->alert->update(['status' => AlertStatus::Resolved, 'resolved_at' => '2026-11-07 09:12:00']);
        $this->mock(IncidentHistoryService::class)->shouldReceive('peaks')->andReturn([$this->alert->id => 3120.0]);

        $this->deliver($this->notification(NotificationEvent::Resolved));

        Http::assertSent(function (Request $request) {
            $payload = json_decode($request->body(), true);

            $this->assertSame('alert.resolved', $request->header('X-Platform-Event')[0]);
            $this->assertSame('resolved', $payload['alert']['status']);
            $this->assertSame('2026-11-07T09:12:00Z', $payload['alert']['resolved_at']);
            $this->assertEquals(3120, $payload['alert']['peak_value']);
            $this->assertStringEndsWith('?tab=history', $payload['alert']['url']);

            return true;
        });
    }

    public function test_the_delivery_id_is_the_same_at_each_attempt_and_differs_between_notifications(): void
    {
        $first = $this->notification();

        $this->assertSame(WebhookPayload::deliveryId($first->id), WebhookPayload::deliveryId($first->id));
        $this->assertNotSame(WebhookPayload::deliveryId($first->id), WebhookPayload::deliveryId($first->id + 1));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', WebhookPayload::deliveryId($first->id));
    }

    // ─── Échecs ─────────────────────────────────────────────────────────

    public function test_an_error_keeps_the_code_and_an_excerpt_then_the_queue_retries(): void
    {
        Http::fake(['itsm.example.com/*' => Http::response('<html><body><h1>Service   Unavailable</h1><p>Try later</p>'.str_repeat('x', 400).'</body></html>', 503)]);
        $notification = $this->notification();

        try {
            $this->deliver($notification);
            $this->fail('WebhookDeliveryException expected');
        } catch (WebhookDeliveryException $e) {
            $this->assertStringStartsWith('HTTP 503: Service Unavailable Try later', $e->getMessage());   // mots séparés
        }

        $notification->refresh();
        $this->assertSame(NotificationStatus::Pending, $notification->status);   // encore des essais
        $this->assertSame(1, $notification->attempts);
        $this->assertLessThanOrEqual(220, strlen($notification->error));
        $this->assertStringNotContainsString('very-secret-token', $notification->error);
    }

    public function test_after_the_last_attempt_the_notification_fails_and_the_webhook_counts_it(): void
    {
        Log::spy();
        $notification = $this->notification();

        (new SendAlertNotification($notification->id))->failed(new WebhookDeliveryException('HTTP 500.'));

        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertSame(1, $this->webhook->fresh()->consecutive_failures);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => ! str_contains(json_encode($context), 'itsm.example.com'));
    }

    public function test_a_success_resets_the_failure_counter(): void
    {
        Http::fake(['itsm.example.com/*' => Http::response('ok')]);
        $this->webhook->forceFill(['consecutive_failures' => 2])->save();

        $this->deliver($this->notification());

        $this->assertSame(0, $this->webhook->fresh()->consecutive_failures);
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['itsm.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

        $this->expectExceptionMessage('HTTP 302: redirect not followed.');
        app(WebhookSender::class)->send($this->webhook, 'alert.opened', ['x' => 1], 'id');
    }

    public function test_a_timeout_is_reported_without_the_url(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds for '.self::URL));

        try {
            app(WebhookSender::class)->send($this->webhook, 'alert.opened', ['x' => 1], 'id');
            $this->fail('WebhookDeliveryException expected');
        } catch (WebhookDeliveryException $e) {
            $this->assertSame('Timeout: no answer within 5 s.', $e->getMessage());
        }
    }

    // ─── SSRF ───────────────────────────────────────────────────────────

    public function test_the_address_is_checked_again_at_send_time(): void
    {
        Http::fake();
        $this->dnsRecords['itsm.example.com'] = ['10.0.0.12'];        // DNS rebinding : le nom pointe maintenant en interne

        try {
            app(WebhookSender::class)->send($this->webhook, 'alert.opened', ['x' => 1], 'id');
            $this->fail('WebhookDeliveryException expected');
        } catch (WebhookDeliveryException $e) {
            $this->assertSame('Refused: the webhook host now points to a non-public address.', $e->getMessage());
        }
        Http::assertNothingSent();

        $this->dnsRecords['itsm.example.com'] = [];
        $this->expectExceptionMessage('The webhook host cannot be found.');
        app(WebhookSender::class)->send($this->webhook, 'alert.opened', ['x' => 1], 'id');
    }

    public function test_the_connection_is_pinned_to_the_checked_address(): void
    {
        $this->dnsRecords['itsm.example.com'] = ['93.184.216.34'];
        $pinned = (fn (string $url) => $this->pinnedAddress($url))->call(app(WebhookSender::class), self::URL);

        $this->assertSame(['curl' => [CURLOPT_RESOLVE => ['itsm.example.com:443:93.184.216.34']]], $pinned);
    }

    public function test_a_demo_host_is_called_without_the_public_check(): void
    {
        config(['monitoring.webhook_allowed_hosts' => ['fake-api']]);
        Http::fake(['fake-api:8080/*' => Http::response('ok')]);
        $this->webhook->update(['url' => 'http://fake-api:8080/webhook']);

        $this->assertSame(200, app(WebhookSender::class)->send($this->webhook->fresh(), 'alert.opened', ['x' => 1], 'id'));
    }

    // ─── Webhook supprimé, désactivé, Slack / Teams ─────────────────────

    public function test_a_deleted_or_disabled_webhook_fails_without_calling(): void
    {
        Http::fake();
        $disabled = $this->notification();
        $this->webhook->update(['active' => false]);
        $this->deliver($disabled);
        $this->assertSame([NotificationStatus::Failed, 'Webhook disabled.', 0], [$disabled->fresh()->status, $disabled->fresh()->error, $disabled->fresh()->attempts]);

        $deleted = $this->notification(NotificationEvent::Resolved);
        $this->webhook->delete();
        $this->deliver($deleted);
        $this->assertSame([NotificationStatus::Failed, 'Webhook deleted.'], [$deleted->fresh()->status, $deleted->fresh()->error]);

        Http::assertNothingSent();
    }

    public function test_slack_and_teams_wait_for_their_format(): void
    {
        Http::fake();
        $this->webhook->update(['type' => WebhookType::Slack]);
        $notification = $this->notification();

        $this->deliver($notification);

        Http::assertNothingSent();
        $this->assertSame(NotificationStatus::Pending, $notification->fresh()->status);
    }

    public function test_the_job_keeps_the_same_retries_as_the_emails(): void
    {
        $job = new SendAlertNotification(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120], $job->backoff);
    }
}
