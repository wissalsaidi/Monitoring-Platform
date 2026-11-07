<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\TeamRole;
use App\Enums\WebhookType;
use App\Exceptions\WebhookDeliveryException;
use App\Jobs\SendAlertNotification;
use App\Mail\WebhookDisabledMail;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Models\Webhook;
use App\Services\WebhookFailureTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\FakesDns;
use Tests\TestCase;

/**
 * Historique des webhooks et désactivation automatique (SCRUM-79).
 */
class WebhookHealthTest extends TestCase
{
    use FakesDns, RefreshDatabase;

    private const URL = 'https://hooks.slack.com/services/T000/B000/XXXXSECRETPATH';

    private Team $team;

    private User $admin;

    private Webhook $webhook;

    private Alert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeDns();
        Mail::fake();
        $this->team = Team::factory()->create(['name' => 'Payments']);
        $this->admin = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin]);
        $target = MonitoringTarget::factory()->for($this->team)->create(['name' => 'Website-01']);
        $this->alert = Alert::factory()->for($target, 'target')->create(['severity' => AlertSeverity::High, 'message' => 'Response time > 2000 ms']);
        $this->webhook = Webhook::factory()->for($this->team)->create(['name' => '#ops-alerts', 'type' => WebhookType::Slack, 'url' => self::URL]);
    }

    /** Une notification par alerte (unique : alerte, destinataire, canal, événement) */
    private function notification(array $attributes = []): AlertNotification
    {
        $alert = AlertNotification::where('alert_id', $this->alert->id)->exists()
            ? Alert::factory()->for($this->alert->target, 'target')->create(['severity' => AlertSeverity::High])
            : $this->alert;

        return AlertNotification::create([
            'alert_id' => $alert->id, 'webhook_id' => $this->webhook->id, 'recipient' => '#ops-alerts',
            'channel' => NotificationChannel::Webhook, 'event' => NotificationEvent::Opened, ...$attributes,
        ]);
    }

    /** Dernier essai épuisé : ce que la queue appelle après le 3e échec */
    private function exhaust(?AlertNotification $notification = null, string $error = 'HTTP 404: no_service'): void
    {
        (new SendAlertNotification(($notification ?? $this->notification())->id))->failed(new WebhookDeliveryException($error, 404));
    }

    // ─── Code HTTP ───────────────────────────────────────────────────────

    public function test_the_http_status_is_kept_for_a_success_and_a_failure(): void
    {
        Http::fakeSequence('hooks.slack.com/*')->push('no_service', 404)->push('ok', 200);
        $notification = $this->notification();

        try {
            (new SendAlertNotification($notification->id))->handle();
            $this->fail('WebhookDeliveryException expected');
        } catch (WebhookDeliveryException $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertSame(404, $notification->fresh()->http_status);

        (new SendAlertNotification($notification->id))->handle();
        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame(200, $notification->http_status);
        $this->assertSame(2, $notification->attempts);
    }

    public function test_no_answer_means_no_http_status(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $notification = $this->notification(['http_status' => 500]);

        try {
            (new SendAlertNotification($notification->id))->handle();
        } catch (WebhookDeliveryException) {
        }

        $this->assertNull($notification->fresh()->http_status);
    }

    // ─── Désactivation automatique ───────────────────────────────────────

    public function test_the_tenth_consecutive_failure_disables_the_webhook(): void
    {
        $manager = User::factory()->create(['email' => 'manager@example.com']);
        $this->team->members()->attach($manager, ['role' => TeamRole::Manager]);
        $inactiveAdmin = User::factory()->create(['email' => 'gone@example.com', 'deactivated_at' => now()]);
        $this->team->members()->attach($inactiveAdmin, ['role' => TeamRole::Admin]);

        for ($i = 1; $i < WebhookFailureTracker::AUTO_DISABLE_AFTER; $i++) {
            $this->exhaust();
        }
        $this->assertTrue($this->webhook->fresh()->active);
        $this->assertSame(9, $this->webhook->fresh()->consecutive_failures);
        Mail::assertNothingQueued();

        $this->exhaust();

        $webhook = $this->webhook->fresh();
        $this->assertFalse($webhook->active);
        $this->assertSame(10, $webhook->consecutive_failures);

        // Les Admin actifs de la team seulement, une fois
        Mail::assertQueued(WebhookDisabledMail::class, 1);
        Mail::assertQueued(WebhookDisabledMail::class, fn (WebhookDisabledMail $mail) => $mail->hasTo('alice@example.com'));

        $log = AuditLog::where('action', 'webhook.auto_disable')->sole();
        $this->assertNull($log->user_id);
        $this->assertSame($this->team->id, $log->team_id);
        $this->assertSame(['name' => '#ops-alerts', 'failures' => 10, 'via' => 'system'], $log->details);

        // Plus d'échec compté comme une nouvelle désactivation
        $this->exhaust();
        Mail::assertQueued(WebhookDisabledMail::class, 1);
        $this->assertSame(1, AuditLog::where('action', 'webhook.auto_disable')->count());
    }

    public function test_a_success_resets_the_counter_before_the_limit(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $this->webhook->forceFill(['consecutive_failures' => 9])->save();

        (new SendAlertNotification($this->notification()->id))->handle();
        $this->assertSame(0, $this->webhook->fresh()->consecutive_failures);

        $this->exhaust();
        $this->assertTrue($this->webhook->fresh()->active);
        $this->assertSame(1, $this->webhook->fresh()->consecutive_failures);
    }

    public function test_a_disabled_webhook_is_no_longer_called_until_enabled_again_by_hand(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $this->webhook->forceFill(['consecutive_failures' => 10, 'active' => false])->save();

        $notification = $this->notification();
        (new SendAlertNotification($notification->id))->handle();
        Http::assertNothingSent();
        $this->assertSame('Webhook disabled.', $notification->fresh()->error);

        $this->actingAs($this->admin)->get(route('teams.notifications.webhooks.index', $this->team))
            ->assertOk()
            ->assertSee('Automatically, after 10 failures');

        $this->actingAs($this->admin)->patch(route('teams.notifications.webhooks.toggle', [$this->team, $this->webhook]));
        $this->assertTrue($this->webhook->fresh()->active);
        $this->assertSame(0, $this->webhook->fresh()->consecutive_failures);
    }

    public function test_the_email_names_the_webhook_without_its_url(): void
    {
        $this->webhook->forceFill(['consecutive_failures' => 10])->save();
        $mail = new WebhookDisabledMail($this->webhook->fresh(), 'HTTP 404: no_service');

        $mail->assertHasSubject('Webhook “#ops-alerts” disabled after 10 failed deliveries');
        $mail->assertSeeInHtml('Payments');
        $mail->assertSeeInHtml('HTTP 404: no_service');
        $mail->assertSeeInHtml(route('teams.notifications.webhooks.index', $this->team));
        $mail->assertDontSeeInHtml('XXXXSECRETPATH');
        $mail->assertDontSeeInHtml('hooks.slack.com');
    }

    public function test_the_audit_shows_system_as_author(): void
    {
        $this->webhook->forceFill(['consecutive_failures' => 9])->save();
        $this->exhaust();

        $this->actingAs($this->admin)->get(route('teams.audit.index', $this->team))
            ->assertOk()
            ->assertSee('Webhook disabled automatically')
            ->assertSee('System');
    }

    // ─── Historique ──────────────────────────────────────────────────────

    public function test_the_history_shows_the_http_status_and_attempts_of_webhooks(): void
    {
        $this->notification(['status' => NotificationStatus::Sent, 'sent_at' => now(), 'attempts' => 2, 'http_status' => 200]);
        $this->notification(['status' => NotificationStatus::Failed, 'attempts' => 3, 'http_status' => 404, 'error' => 'HTTP 404: no_service']);

        $this->actingAs($this->admin)->get(route('teams.notifications.history', $this->team))
            ->assertOk()
            ->assertSee('#ops-alerts')
            ->assertSee('HTTP 200 · 2 attempts')
            ->assertSee('HTTP 404 ·')
            ->assertSee('after 3 attempts: HTTP 404: no_service')
            ->assertDontSee('XXXXSECRETPATH');
    }

    public function test_the_history_filters_by_channel_and_webhook(): void
    {
        $other = Webhook::factory()->for($this->team)->create(['name' => 'ITSM']);
        $this->notification(['status' => NotificationStatus::Sent]);
        AlertNotification::create([
            'alert_id' => $this->alert->id, 'webhook_id' => $other->id, 'recipient' => 'ITSM',
            'channel' => NotificationChannel::Webhook, 'event' => NotificationEvent::Opened,
        ]);
        AlertNotification::create([
            'alert_id' => $this->alert->id, 'user_id' => $this->admin->id, 'recipient' => 'alice@example.com',
            'channel' => NotificationChannel::Email, 'event' => NotificationEvent::Opened,
        ]);
        $history = fn (array $query) => $this->actingAs($this->admin)->get(route('teams.notifications.history', [$this->team, ...$query]));

        $history([])->assertViewHas('notifications', fn ($page) => $page->total() === 3);
        $history(['channel' => 'email'])->assertViewHas('notifications', fn ($page) => $page->pluck('channel')->unique()->all() === [NotificationChannel::Email]);
        $history(['channel' => 'webhook'])->assertViewHas('notifications', fn ($page) => $page->total() === 2);
        $history(['webhook' => $other->id])->assertViewHas('notifications', fn ($page) => $page->pluck('recipient')->all() === ['ITSM'])
            ->assertSee('All webhooks');

        // Canal « dashboard » (jamais envoyé) et webhook d'une autre team : refusés
        $history(['channel' => 'dashboard'])->assertSessionHasErrors('channel');
        $foreign = Webhook::factory()->create();
        $history(['webhook' => $foreign->id])->assertSessionHasErrors('webhook');
    }

    public function test_the_webhooks_tab_links_to_the_deliveries_of_each_webhook(): void
    {
        $this->notification(['status' => NotificationStatus::Sent, 'sent_at' => now(), 'http_status' => 200]);

        $this->actingAs($this->admin)->get(route('teams.notifications.webhooks.index', $this->team))
            ->assertOk()
            ->assertSee(route('teams.notifications.history', [$this->team, 'webhook' => $this->webhook->id]), false);
    }
}
