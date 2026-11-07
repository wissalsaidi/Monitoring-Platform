<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\TeamRole;
use App\Enums\WebhookType;
use App\Http\Controllers\WebhookController;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Models\Webhook;
use App\Services\WebhookSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesDns;
use Tests\TestCase;

/**
 * Onglet « Webhooks » de la page Notifications (SCRUM-78) : liste, gestion par Admin et Manager, URL masquée,
 * secret montré une fois, bouton « Send test », audit sans URL ni secret.
 */
class WebhookPageTest extends TestCase
{
    use FakesDns, RefreshDatabase;

    private const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/XXXXSECRETPATH';

    private Team $team;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeDns();
        $this->team = Team::factory()->create(['name' => 'Payments']);
        $this->manager = $this->member(TeamRole::Manager);
    }

    private function member(TeamRole $role, ?Team $team = null): User
    {
        $user = User::factory()->create();
        ($team ?? $this->team)->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private function form(array $overrides = []): array
    {
        return array_replace([
            'name' => '#ops-alerts', 'type' => 'slack', 'url' => self::SLACK_URL,
            'severities' => ['critical', 'high'], 'events' => ['opened', 'resolved'],
        ], $overrides);
    }

    private function webhook(array $attributes = []): Webhook
    {
        return Webhook::factory()->for($this->team)->create(['name' => '#ops-alerts', 'type' => WebhookType::Slack, 'url' => self::SLACK_URL, ...$attributes]);
    }

    private function route(string $name, ?Webhook $webhook = null): string
    {
        return route("teams.notifications.webhooks.{$name}", array_filter([$this->team, $webhook]));
    }

    // ─── Liste ───────────────────────────────────────────────────────────

    public function test_the_list_shows_each_webhook_with_its_status_and_last_delivery(): void
    {
        $webhook = $this->webhook(['consecutive_failures' => 2]);
        $this->webhook(['name' => 'ITSM', 'type' => WebhookType::Generic, 'url' => 'https://itsm.example.com/hook', 'active' => false]);
        $target = MonitoringTarget::factory()->for($this->team)->create();
        AlertNotification::create([
            'alert_id' => Alert::factory()->for($target, 'target')->create()->id, 'webhook_id' => $webhook->id, 'recipient' => '#ops-alerts',
            'channel' => NotificationChannel::Webhook, 'event' => NotificationEvent::Opened, 'status' => NotificationStatus::Sent,
            'sent_at' => '2026-11-07 09:30:00',
        ]);

        $this->actingAs($this->manager)->get($this->route('index'))
            ->assertOk()
            ->assertSee('Webhooks')
            ->assertSeeInOrder(['#ops-alerts', 'https://hooks.slack.com/…/••••', 'Slack', 'CRITICAL, HIGH', 'Opened, Resolved', 'Enabled', '2 failed in a row', 'SENT'])
            ->assertSeeInOrder(['ITSM', 'Generic (signed JSON)', 'Disabled', 'Never'])
            ->assertSee('Send test')
            ->assertSee('New webhook')
            ->assertDontSee('XXXXSECRETPATH');
    }

    public function test_a_viewer_sees_the_list_without_urls_or_actions(): void
    {
        $this->webhook();

        $this->actingAs($this->member(TeamRole::Viewer))->get($this->route('index'))
            ->assertOk()
            ->assertSee('#ops-alerts')
            ->assertSee('Read only')
            ->assertDontSee('hooks.slack.com')
            ->assertDontSee('Send test')
            ->assertDontSee('New webhook');
    }

    public function test_a_viewer_cannot_change_anything(): void
    {
        $viewer = $this->member(TeamRole::Viewer);
        $webhook = $this->webhook();

        $this->actingAs($viewer)->post($this->route('store'), $this->form(['name' => 'Other']))->assertForbidden();
        $this->actingAs($viewer)->get($this->route('edit', $webhook))->assertForbidden();
        $this->actingAs($viewer)->put($this->route('update', $webhook), $this->form(['name' => 'Renamed']))->assertForbidden();
        $this->actingAs($viewer)->patch($this->route('toggle', $webhook))->assertForbidden();
        $this->actingAs($viewer)->delete($this->route('destroy', $webhook))->assertForbidden();
        $this->actingAs($viewer)->post($this->route('test', $webhook))->assertForbidden();

        $this->assertSame(1, Webhook::count());
        $this->assertSame('#ops-alerts', $webhook->fresh()->name);
        $this->assertTrue($webhook->fresh()->active);
    }

    public function test_another_team_cannot_reach_the_webhooks(): void
    {
        $webhook = $this->webhook();
        $other = Team::factory()->create();
        $outsider = $this->member(TeamRole::Admin, $other);

        $this->actingAs($outsider)->get($this->route('index'))->assertForbidden();
        $this->actingAs($outsider)->delete($this->route('destroy', $webhook))->assertForbidden();
        // Webhook d'une autre team sous l'URL de sa propre team : introuvable
        $this->actingAs($outsider)->delete(route('teams.notifications.webhooks.destroy', [$other, $webhook]))->assertNotFound();
        $this->assertSame(1, Webhook::count());
    }

    // ─── Création ────────────────────────────────────────────────────────

    public function test_a_manager_creates_a_slack_webhook(): void
    {
        $this->actingAs($this->manager)->post($this->route('store'), $this->form())
            ->assertRedirect($this->route('index'))
            ->assertSessionHas('success')
            ->assertSessionMissing('webhook_secret');

        $webhook = Webhook::sole();
        $this->assertSame(WebhookType::Slack, $webhook->type);
        $this->assertSame(self::SLACK_URL, $webhook->url);
        $this->assertTrue($webhook->active);
        $this->assertEquals([AlertSeverity::Critical, AlertSeverity::High], $webhook->severities->all());

        $log = AuditLog::where('action', 'webhook.create')->sole();
        $this->assertSame($this->team->id, $log->team_id);
        $this->assertSame(['name' => '#ops-alerts', 'type' => 'Slack', 'severities' => 'CRITICAL, HIGH', 'events' => 'Opened, Resolved'], $log->details);
        $this->assertStringNotContainsString('SECRETPATH', json_encode($log->details));
    }

    public function test_the_secret_of_a_generic_webhook_is_shown_once(): void
    {
        $response = $this->actingAs($this->manager)->post($this->route('store'), $this->form(['name' => 'ITSM', 'type' => 'generic', 'url' => 'https://itsm.example.com/hook']));
        $webhook = Webhook::sole();
        $response->assertSessionHas('webhook_secret', ['id' => $webhook->id, 'secret' => $webhook->secret]);

        $this->followRedirects($response)->assertSee($webhook->secret)->assertSee('it will not be shown again');
        $this->actingAs($this->manager)->get($this->route('index'))->assertOk()->assertDontSee($webhook->secret);
        $this->assertStringNotContainsString($webhook->secret, json_encode(AuditLog::pluck('details')));
    }

    public function test_the_form_is_validated(): void
    {
        $this->webhook();

        $this->actingAs($this->manager)->post($this->route('store'), $this->form())->assertSessionHasErrors('name');
        $this->actingAs($this->manager)->post($this->route('store'), $this->form(['name' => 'A', 'url' => 'http://hooks.slack.com/x']))
            ->assertSessionHasErrors(['url' => 'A webhook URL must use HTTPS.']);
        $this->dnsRecords['internal.example.com'] = ['10.0.0.5'];
        $this->actingAs($this->manager)->post($this->route('store'), $this->form(['name' => 'B', 'url' => 'https://internal.example.com/hook']))
            ->assertSessionHasErrors('url');
        $this->actingAs($this->manager)->post($this->route('store'), $this->form(['name' => 'C', 'type' => 'email']))->assertSessionHasErrors('type');
        $this->actingAs($this->manager)->post($this->route('store'), $this->form(['name' => 'D', 'severities' => []]))
            ->assertSessionHasErrors(['severities' => 'Choose at least one severity.']);
        $this->actingAs($this->manager)->post($this->route('store'), $this->form(['name' => 'E', 'events' => ['closed']]))->assertSessionHasErrors('events.0');

        $this->assertSame(1, Webhook::count());
    }

    public function test_a_team_has_at_most_ten_webhooks(): void
    {
        Webhook::factory()->for($this->team)->count(WebhookController::MAX_PER_TEAM)->create();

        $this->actingAs($this->manager)->post($this->route('store'), $this->form())->assertSessionHas('error');
        $this->assertSame(WebhookController::MAX_PER_TEAM, Webhook::count());
    }

    // ─── Modification ────────────────────────────────────────────────────

    public function test_the_edit_form_never_shows_the_url(): void
    {
        $webhook = $this->webhook();

        $this->actingAs($this->manager)->get($this->route('edit', $webhook))
            ->assertOk()
            ->assertSee('https://hooks.slack.com/…/••••')
            ->assertSee('Leave empty to keep the current URL')
            ->assertDontSee('XXXXSECRETPATH');
    }

    public function test_an_update_keeps_the_url_when_left_empty_and_is_audited(): void
    {
        $webhook = $this->webhook();

        $this->actingAs($this->manager)->put($this->route('update', $webhook), $this->form(['name' => '#payments', 'url' => '', 'severities' => ['critical'], 'active' => '1']))
            ->assertRedirect($this->route('index'));

        $webhook->refresh();
        $this->assertSame('#payments', $webhook->name);
        $this->assertSame(self::SLACK_URL, $webhook->url);
        $log = AuditLog::where('action', 'webhook.update')->sole();
        $this->assertSame(['name' => '#payments', 'changes' => ['name' => ['#ops-alerts', '#payments'], 'severities' => ['CRITICAL, HIGH', 'CRITICAL']]], $log->details);
    }

    public function test_a_new_url_is_audited_without_its_value(): void
    {
        $webhook = $this->webhook();
        $newUrl = 'https://hooks.slack.com/services/T000/B000/NEWSECRETPATH';

        $this->actingAs($this->manager)->put($this->route('update', $webhook), $this->form(['url' => $newUrl, 'active' => '1']));

        $this->assertSame($newUrl, $webhook->fresh()->url);
        $log = AuditLog::where('action', 'webhook.update')->sole();
        $this->assertSame(['name' => '#ops-alerts', 'URL' => 'changed'], $log->details);
        $this->assertStringNotContainsString('NEWSECRETPATH', json_encode($log->details));
    }

    public function test_an_update_without_change_is_not_audited(): void
    {
        $webhook = $this->webhook();

        $this->actingAs($this->manager)->put($this->route('update', $webhook), $this->form(['url' => '', 'active' => '1']))
            ->assertSessionHas('success', 'No change.');
        $this->assertSame(0, AuditLog::where('action', 'webhook.update')->count());
    }

    public function test_disabling_and_enabling_resets_the_failure_counter(): void
    {
        $webhook = $this->webhook(['consecutive_failures' => 4]);

        $this->actingAs($this->manager)->patch($this->route('toggle', $webhook))->assertSessionHas('success', 'Webhook “#ops-alerts” disabled.');
        $this->assertFalse($webhook->fresh()->active);
        $this->assertSame(4, $webhook->fresh()->consecutive_failures);

        $this->actingAs($this->manager)->patch($this->route('toggle', $webhook));
        $this->assertTrue($webhook->fresh()->active);
        $this->assertSame(0, $webhook->fresh()->consecutive_failures);

        $this->assertSame(
            [['active' => [true, false]], ['active' => [false, true]]],
            AuditLog::where('action', 'webhook.update')->orderBy('id')->get()->map(fn ($log) => $log->details['changes'])->all(),
        );
    }

    // ─── Suppression et secret ───────────────────────────────────────────

    public function test_a_deleted_webhook_keeps_its_delivery_history(): void
    {
        $webhook = $this->webhook();
        $target = MonitoringTarget::factory()->for($this->team)->create();
        $notification = AlertNotification::create([
            'alert_id' => Alert::factory()->for($target, 'target')->create()->id, 'webhook_id' => $webhook->id, 'recipient' => '#ops-alerts',
            'channel' => NotificationChannel::Webhook, 'event' => NotificationEvent::Opened, 'status' => NotificationStatus::Sent,
        ]);

        $this->actingAs($this->manager)->patch($this->route('toggle', $webhook));
        $this->actingAs($this->manager)->delete($this->route('destroy', $webhook))->assertRedirect($this->route('index'));

        $this->assertSame(0, Webhook::count());
        $this->assertNull($notification->fresh()->webhook_id);
        $log = AuditLog::where('action', 'webhook.delete')->sole();
        $this->assertSame($this->team->id, $log->team_id);
        $this->assertSame('#ops-alerts (deleted)', $log->subjectLabel());
        $this->assertSame('#ops-alerts (deleted)', AuditLog::where('action', 'webhook.update')->sole()->subjectLabel());
    }

    public function test_the_secret_of_a_generic_webhook_can_be_regenerated(): void
    {
        $webhook = $this->webhook(['name' => 'ITSM', 'type' => WebhookType::Generic, 'url' => 'https://itsm.example.com/hook']);
        $old = $webhook->secret;

        $this->actingAs($this->manager)->post($this->route('secret', $webhook))->assertSessionHas('webhook_secret');

        $new = $webhook->fresh()->secret;
        $this->assertNotSame($old, $new);
        $this->assertSame(48, strlen($new));
        $log = AuditLog::where('action', 'webhook.secret_rotate')->sole();
        $this->assertSame(['name' => 'ITSM'], $log->details);
        $this->assertSame('Webhook ITSM', $log->subjectLabel());

        // Slack et Teams ne signent pas : pas de secret à régénérer
        $this->actingAs($this->manager)->post($this->route('secret', $this->webhook(['name' => 'Slack'])))->assertNotFound();
    }

    // ─── Send test ───────────────────────────────────────────────────────

    public function test_send_test_posts_a_sample_alert_and_shows_the_result(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $webhook = $this->webhook(['consecutive_failures' => 3]);

        $response = $this->actingAs($this->manager)->from($this->route('index'))->post($this->route('test', $webhook));

        $response->assertRedirect($this->route('index'));
        $result = session('webhook_test');
        $this->assertSame($webhook->id, $result['id']);
        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
        $this->assertMatchesRegularExpression('/^HTTP 200 in \d+ ms\.$/', $result['message']);
        $this->followRedirects($response)->assertSee('Test sent: HTTP 200 in', false);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->url() === self::SLACK_URL
                && $body['text'] === '🔴 [CRITICAL] Sample target'
                && $body['attachments'][0]['blocks'][3]['elements'][0]['url'] === $this->route('index');
        });
        // Rien n'est enregistré, le compteur d'échecs n'est pas touché
        $this->assertSame(0, Alert::count());
        $this->assertSame(0, AlertNotification::count());
        $this->assertSame(3, $webhook->fresh()->consecutive_failures);
    }

    public function test_send_test_of_a_generic_webhook_is_signed_and_marked_as_a_test(): void
    {
        Http::fake(['itsm.example.com/*' => Http::response('', 204)]);
        $webhook = $this->webhook(['name' => 'ITSM', 'type' => WebhookType::Generic, 'url' => 'https://itsm.example.com/hook', 'severities' => [AlertSeverity::Warning]]);

        $this->actingAs($this->manager)->post($this->route('test', $webhook));

        Http::assertSent(function ($request) use ($webhook) {
            $body = json_decode($request->body(), true);

            return $request->header('X-Platform-Event')[0] === 'alert.test'
                && $request->header('X-Platform-Signature')[0] === WebhookSender::signature($webhook->secret, $request->header('X-Platform-Timestamp')[0], $request->body())
                && $body['event'] === 'alert.test' && $body['test'] === true
                && $body['alert']['severity'] === 'warning' && $body['target']['name'] === 'Sample target';
        });
        $this->assertSame(204, session('webhook_test.status'));
    }

    public function test_a_failed_test_shows_the_error_without_the_url(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('invalid_token', 403)]);
        $webhook = $this->webhook();

        $response = $this->actingAs($this->manager)->from($this->route('index'))->post($this->route('test', $webhook));

        $this->assertFalse(session('webhook_test.ok'));
        $this->assertSame('HTTP 403: invalid_token', session('webhook_test.message'));
        $this->followRedirects($response)->assertSee('Test failed after')->assertSee('HTTP 403: invalid_token')->assertDontSee('XXXXSECRETPATH');
    }

    // ─── Audit ───────────────────────────────────────────────────────────

    public function test_webhook_actions_have_readable_labels(): void
    {
        $this->actingAs($this->manager)->post($this->route('store'), $this->form());
        $admin = $this->member(TeamRole::Admin);

        $this->actingAs($admin)->get(route('teams.audit.index', $this->team))
            ->assertOk()
            ->assertSee('Webhook created')
            ->assertSee('Webhook #ops-alerts')
            ->assertDontSee('XXXXSECRETPATH');
    }
}
