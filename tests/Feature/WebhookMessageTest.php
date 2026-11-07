<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\NotificationEvent;
use App\Enums\TargetType;
use App\Enums\WebhookType;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\Webhook;
use App\Services\WebhookMessage;
use App\Services\WebhookPayload;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Messages Slack et Microsoft Teams (SCRUM-77).
 */
class WebhookMessageTest extends TestCase
{
    use RefreshDatabase;

    private Alert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Paris', 'app.name' => 'Monitoring Platform']);
        $team = Team::factory()->create(['name' => 'Payments']);
        $target = MonitoringTarget::factory()->for($team)->create(['name' => 'Website-01', 'type' => TargetType::Website]);
        $this->alert = Alert::factory()->for($target, 'target')->create([
            'severity' => AlertSeverity::High, 'metric' => 'response_time', 'value' => 3017.004, 'threshold' => 2000,
            'message' => 'Response time > 2000 ms on <Website-01> & co', 'started_at' => '2026-11-07 09:00:00',
        ]);
    }

    /** @return array<string, string> titre => valeur */
    private function facts(array $fields): array
    {
        return collect($fields)->mapWithKeys(function ($field) {
            [$title, $value] = explode("\n", $field['text'], 2);

            return [trim($title, '*') => $value];
        })->all();
    }

    // ─── Slack ──────────────────────────────────────────────────────────

    public function test_slack_opening_message(): void
    {
        $message = WebhookMessage::slack($this->alert, NotificationEvent::Opened);
        $attachment = $message['attachments'][0];
        [$header, $text, $fields, $actions, $context] = $attachment['blocks'];

        $this->assertSame('🟠 [HIGH] Website-01', $message['text']);                     // texte de repli
        $this->assertSame('#f57c00', $attachment['color']);
        $this->assertSame(['type' => 'plain_text', 'text' => '🟠 [HIGH] Website-01', 'emoji' => true], $header['text']);
        $this->assertSame('Response time &gt; 2000 ms on &lt;Website-01&gt; &amp; co', $text['text']['text']);   // échappé
        $this->assertSame([
            'Target' => 'Website-01 (Website)',
            'Metric' => 'Response time',
            'Value / threshold' => '3,017 ms / &gt; 2,000 ms',
            'Started' => '07/11/2026 10:00 CET',
        ], $this->facts($fields['fields']));
        $this->assertSame('View alert', $actions['elements'][0]['text']['text']);
        $this->assertSame(route('targets.show', [$this->alert->target, 'tab' => 'alerts']), $actions['elements'][0]['url']);
        $this->assertSame('danger', $actions['elements'][0]['style']);
        $this->assertStringContainsString('Team *Payments*', $context['elements'][0]['text']);
    }

    public function test_slack_resolution_message_has_duration_and_maximum(): void
    {
        $this->alert->update(['status' => AlertStatus::Resolved, 'resolved_at' => '2026-11-07 09:12:30']);

        $message = WebhookMessage::slack($this->alert->fresh(), NotificationEvent::Resolved, 3028.5);
        $attachment = $message['attachments'][0];
        $facts = $this->facts($attachment['blocks'][2]['fields']);

        $this->assertSame('✅ [RESOLVED] Website-01', $message['text']);
        $this->assertSame('#2e7d32', $attachment['color']);
        $this->assertSame('12m 30s', $facts['Duration']);
        $this->assertSame('3,029 ms', $facts['Maximum']);
        $this->assertArrayNotHasKey('style', $attachment['blocks'][3]['elements'][0]);
        $this->assertStringEndsWith('?tab=history', $attachment['blocks'][3]['elements'][0]['url']);
    }

    public function test_slack_colors_follow_the_severity(): void
    {
        foreach (['critical' => '#d32f2f', 'high' => '#f57c00', 'warning' => '#fbc02d', 'info' => '#1976d2'] as $severity => $color) {
            $this->alert->severity = AlertSeverity::from($severity);
            $this->assertSame($color, WebhookMessage::slack($this->alert, NotificationEvent::Opened)['attachments'][0]['color']);
        }
        $this->alert->severity = AlertSeverity::Critical;
        $this->assertSame('🔴 [CRITICAL] Website-01', WebhookMessage::title($this->alert, NotificationEvent::Opened));
    }

    // ─── Microsoft Teams ────────────────────────────────────────────────

    public function test_teams_adaptive_card(): void
    {
        $message = WebhookMessage::teams($this->alert, NotificationEvent::Opened);
        $attachment = $message['attachments'][0];
        $card = $attachment['content'];

        $this->assertSame('message', $message['type']);
        $this->assertSame('application/vnd.microsoft.card.adaptive', $attachment['contentType']);
        $this->assertSame(['AdaptiveCard', '1.4'], [$card['type'], $card['version']]);
        $this->assertSame('🟠 [HIGH] Website-01', $card['body'][0]['text']);
        $this->assertSame('Attention', $card['body'][0]['color']);
        $this->assertSame('Response time > 2000 ms on <Website-01> & co', $card['body'][1]['text']);   // pas d'échappement Slack
        $this->assertSame('FactSet', $card['body'][2]['type']);
        $this->assertSame([
            ['title' => 'Target', 'value' => 'Website-01 (Website)'],
            ['title' => 'Metric', 'value' => 'Response time'],
            ['title' => 'Value / threshold', 'value' => '3,017 ms / > 2,000 ms'],
            ['title' => 'Started', 'value' => '07/11/2026 10:00 CET'],
        ], $card['body'][2]['facts']);
        $this->assertSame(['type' => 'Action.OpenUrl', 'title' => 'View alert', 'url' => route('targets.show', [$this->alert->target, 'tab' => 'alerts'])], $card['actions'][0]);
    }

    public function test_teams_resolution_card(): void
    {
        $this->alert->update(['status' => AlertStatus::Resolved, 'resolved_at' => '2026-11-07 09:12:30', 'peak_value' => 3100]);

        $card = WebhookMessage::teams($this->alert->fresh(), NotificationEvent::Resolved)['attachments'][0]['content'];
        $facts = collect($card['body'][2]['facts'])->pluck('value', 'title');

        $this->assertSame('Good', $card['body'][0]['color']);
        $this->assertSame('✅ [RESOLVED] Website-01', $card['body'][0]['text']);
        $this->assertSame('12m 30s', $facts['Duration']);
        $this->assertSame('3,100 ms', $facts['Maximum']);                       // valeur gardée sur l'alerte
    }

    // ─── Commande webhooks:try ───────────────────────────────────────────

    public function test_webhooks_try_sends_a_sample_without_storing_anything(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        $this->artisan('webhooks:try', ['type' => 'slack'])
            ->expectsQuestion('Webhook URL (hidden)', 'https://hooks.slack.com/services/T0/B0/SECRET')
            ->expectsOutputToContain("Slack message sent (HTTP 200), built from alert #{$this->alert->id}")
            ->doesntExpectOutputToContain('SECRET')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => json_decode($request->body(), true)['text'] === '🟠 [HIGH] Website-01');
        $this->assertSame(0, Webhook::count());

        $this->artisan('webhooks:try', ['type' => 'teams', '--resolved' => true])
            ->expectsQuestion('Webhook URL (hidden)', 'https://hooks.slack.com/services/T0/B0/SECRET')
            ->assertSuccessful();
        Http::assertSent(fn ($request) => str_contains($request->body(), '[RESOLVED]'));
    }

    public function test_webhooks_try_refuses_bad_input(): void
    {
        $this->artisan('webhooks:try', ['type' => 'email'])->assertFailed();
        $this->artisan('webhooks:try', ['type' => 'slack'])
            ->expectsQuestion('Webhook URL (hidden)', 'http://127.0.0.1/hook')
            ->expectsOutputToContain('HTTPS')
            ->assertFailed();

        Alert::query()->delete();
        $this->artisan('webhooks:try', ['type' => 'slack'])->expectsOutputToContain('No alert')->assertFailed();
    }

    // ─── Choix du format ────────────────────────────────────────────────

    public function test_the_webhook_type_selects_the_format(): void
    {
        $build = fn (WebhookType $type) => WebhookPayload::forWebhook(
            Webhook::factory()->make(['type' => $type]), $this->alert, NotificationEvent::Opened, 'delivery-id',
        );

        $this->assertSame(1, $build(WebhookType::Generic)['version']);
        $this->assertArrayHasKey('attachments', $build(WebhookType::Slack));
        $this->assertArrayHasKey('blocks', $build(WebhookType::Slack)['attachments'][0]);
        $this->assertSame('AdaptiveCard', $build(WebhookType::Teams)['attachments'][0]['content']['type']);
    }
}
