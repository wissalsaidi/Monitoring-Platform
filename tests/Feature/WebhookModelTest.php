<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\TeamRole;
use App\Enums\WebhookType;
use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Models\Webhook;
use App\Rules\WebhookUrl;
use App\Services\AlertNotifier;
use App\Services\NotificationRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\FakesDns;
use Tests\TestCase;

/**
 * Modèle des webhooks et règles d'abonnement (SCRUM-75).
 */
class WebhookModelTest extends TestCase
{
    use FakesDns, RefreshDatabase;

    private Team $team;

    private MonitoringTarget $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDns();
        $this->team = Team::factory()->create();
        $this->team->members()->attach(User::factory()->create(['email' => 'admin@company.com']), ['role' => TeamRole::Admin]);
        $this->target = MonitoringTarget::factory()->for($this->team)->create();
    }

    private function alert(AlertSeverity $severity = AlertSeverity::Critical): Alert
    {
        return Alert::factory()->for($this->target, 'target')->create(['severity' => $severity]);
    }

    private function webhook(array $attributes = []): Webhook
    {
        return Webhook::factory()->for($this->team)->create(['name' => 'Ops Slack', 'type' => WebhookType::Slack, ...$attributes]);
    }

    // ─── Modèle ─────────────────────────────────────────────────────────

    public function test_url_and_secret_are_encrypted_and_hidden(): void
    {
        $webhook = $this->webhook(['url' => 'https://hooks.slack.com/services/T000/B000/XXXX']);

        $row = DB::table('webhooks')->where('id', $webhook->id)->first();
        $this->assertStringNotContainsString('hooks.slack.com', $row->url);
        $this->assertNotSame($webhook->secret, $row->secret);
        $this->assertSame('https://hooks.slack.com/services/T000/B000/XXXX', $webhook->fresh()->url);

        $this->assertSame(48, strlen($webhook->secret));                  // secret créé automatiquement
        $this->assertArrayNotHasKey('url', $webhook->toArray());
        $this->assertArrayNotHasKey('secret', $webhook->toArray());
        $this->assertSame('https://hooks.slack.com/…', $webhook->maskedUrl());
    }

    public function test_the_secret_cannot_be_mass_assigned(): void
    {
        $webhook = Webhook::create([
            'team_id' => $this->team->id, 'name' => 'X', 'type' => 'generic', 'url' => 'https://x.example.com',
            'severities' => ['critical'], 'events' => ['opened'], 'secret' => 'chosen-by-attacker',
        ]);

        $this->assertNotSame('chosen-by-attacker', $webhook->fresh()->secret);
    }

    public function test_subscriptions_by_severity_and_event(): void
    {
        $webhook = $this->webhook(['severities' => [AlertSeverity::Critical], 'events' => [NotificationEvent::Opened]]);

        $this->assertTrue($webhook->subscribedTo(AlertSeverity::Critical, NotificationEvent::Opened));
        $this->assertFalse($webhook->subscribedTo(AlertSeverity::Critical, NotificationEvent::Resolved));
        $this->assertFalse($webhook->subscribedTo(AlertSeverity::High, NotificationEvent::Opened));

        $webhook->update(['active' => false]);
        $this->assertFalse($webhook->subscribedTo(AlertSeverity::Critical, NotificationEvent::Opened));
    }

    public function test_deleting_a_webhook_keeps_its_history_rows(): void
    {
        $webhook = $this->webhook();
        $notification = AlertNotification::create([
            'alert_id' => $this->alert()->id, 'webhook_id' => $webhook->id, 'recipient' => 'Ops Slack',
            'channel' => NotificationChannel::Webhook, 'event' => NotificationEvent::Opened,
        ]);

        $webhook->delete();

        $this->assertNull($notification->fresh()->webhook_id);
        $this->assertSame('Ops Slack', $notification->fresh()->recipient);
    }

    // ─── Routage ────────────────────────────────────────────────────────

    public function test_a_notification_is_planned_for_each_subscribed_webhook_next_to_the_emails(): void
    {
        $slack = $this->webhook();
        $this->webhook(['name' => 'Only high', 'severities' => [AlertSeverity::High]]);
        $this->webhook(['name' => 'Paused', 'active' => false]);
        Webhook::factory()->create(['name' => 'Other team']);

        $planned = app(NotificationRouter::class)->plan($this->alert(), NotificationEvent::Opened);

        $this->assertSame(['admin@company.com', 'Ops Slack'], $planned->pluck('recipient')->all());
        $webhookRow = $planned->firstWhere('channel', NotificationChannel::Webhook);
        $this->assertSame($slack->id, $webhookRow->webhook_id);
        $this->assertNull($webhookRow->user_id);
        $this->assertSame(NotificationStatus::Pending, $webhookRow->status);
    }

    public function test_webhooks_do_not_depend_on_the_email_rules(): void
    {
        $this->webhook(['severities' => [AlertSeverity::Info]]);

        // INFO : « dashboard only » pour les emails par défaut, mais le webhook y est abonné
        $planned = app(NotificationRouter::class)->plan($this->alert(AlertSeverity::Info), NotificationEvent::Opened);

        $this->assertSame(['Ops Slack'], $planned->pluck('recipient')->all());
    }

    public function test_never_twice_the_same_webhook_notification(): void
    {
        $this->webhook();
        $alert = $this->alert();
        $router = app(NotificationRouter::class);

        $this->assertCount(2, $router->plan($alert, NotificationEvent::Opened));
        $this->assertCount(0, $router->plan($alert, NotificationEvent::Opened));
        $this->assertSame(1, AlertNotification::where('channel', 'webhook')->count());
    }

    public function test_resolution_only_for_webhooks_told_about_the_opening(): void
    {
        $told = $this->webhook();
        $alert = $this->alert();
        app(NotificationRouter::class)->plan($alert, NotificationEvent::Opened);
        $this->webhook(['name' => 'Added later']);                         // créé après l'ouverture
        $failed = $this->webhook(['name' => 'Failed']);
        AlertNotification::create([
            'alert_id' => $alert->id, 'webhook_id' => $failed->id, 'recipient' => 'Failed', 'channel' => NotificationChannel::Webhook,
            'event' => NotificationEvent::Opened, 'status' => NotificationStatus::Failed,
        ]);

        $alert->update(['status' => AlertStatus::Resolved, 'resolved_at' => now()]);
        $webhooks = app(NotificationRouter::class)->webhooks($alert, NotificationEvent::Resolved);

        $this->assertSame([$told->id], $webhooks->pluck('id')->all());
    }

    public function test_the_notifier_queues_webhook_notifications_and_the_job_does_not_email_them(): void
    {
        Queue::fake();
        $this->webhook();
        $alert = $this->alert();

        $this->assertSame(2, app(AlertNotifier::class)->alertOpened($alert));
        Queue::assertPushed(SendAlertNotification::class, 2);

        Mail::fake();
        Http::fake(['hooks.example.com/*' => Http::response('ok')]);
        $webhookRow = AlertNotification::where('channel', 'webhook')->sole();
        (new SendAlertNotification($webhookRow->id))->handle();
        Mail::assertNothingSent();                                          // envoyé en HTTP (SCRUM-76, 77), pas par email
        $this->assertSame(NotificationStatus::Sent, $webhookRow->fresh()->status);
    }

    public function test_the_rules_page_still_offers_only_email_or_dashboard(): void
    {
        $this->withoutVite();
        $admin = $this->team->members()->first();

        $this->actingAs($admin)->get(route('teams.notifications.index', $this->team))->assertOk()->assertDontSee('value="webhook"', false);
        $this->actingAs($admin)->from(route('teams.notifications.index', $this->team))
            ->put(route('teams.notifications.update', $this->team), ['rules' => ['critical' => ['opened' => 'webhook']]])
            ->assertSessionHasErrors();
    }

    public function test_the_notification_history_shows_webhook_rows(): void
    {
        $this->withoutVite();
        $this->webhook();
        app(NotificationRouter::class)->plan($this->alert(), NotificationEvent::Opened);
        $viewer = User::factory()->create();
        $this->team->members()->attach($viewer, ['role' => TeamRole::Viewer]);

        $this->actingAs($viewer)->get(route('teams.notifications.history', $this->team))->assertOk()
            ->assertSee(['Ops Slack', 'Webhook', 'bi-broadcast'])->assertDontSee('Deleted user');

        Webhook::where('name', 'Ops Slack')->delete();
        $this->actingAs($viewer)->get(route('teams.notifications.history', $this->team))
            ->assertSee('(deleted)');
    }

    // ─── URL ────────────────────────────────────────────────────────────

    public function test_webhook_url_rule(): void
    {
        $this->dnsRecords['intranet.company.com'] = ['10.0.0.5'];
        $validate = fn (string $url) => Validator::make(['url' => $url], ['url' => [new WebhookUrl]]);

        $this->assertTrue($validate('https://hooks.slack.com/services/T0/B0/X')->passes());
        $this->assertSame(WebhookUrl::HTTPS, $validate('http://hooks.slack.com/services/T0/B0/X')->errors()->first('url'));
        $this->assertFalse($validate('https://127.0.0.1/hook')->passes());
        $this->assertFalse($validate('https://localhost/hook')->passes());
        $this->assertFalse($validate('https://intranet.company.com/hook')->passes());   // résolu en adresse privée
        $this->assertFalse($validate('not a url')->passes());
        $this->assertFalse($validate('ftp://files.example.com/hook')->passes());

        // Hôte de démo : HTTP et réseau interne acceptés
        config(['monitoring.webhook_allowed_hosts' => ['fake-api']]);
        $this->assertTrue($validate('http://fake-api:8080/webhook')->passes());

        // La liste des targets (allowed_internal_hosts) ne vaut pas pour les webhooks
        config(['monitoring.allowed_internal_hosts' => ['demo-website-01'], 'monitoring.webhook_allowed_hosts' => []]);
        $this->dnsRecords['demo-website-01'] = ['172.19.0.8'];
        $this->assertFalse($validate('https://demo-website-01/hook')->passes());
    }
}
