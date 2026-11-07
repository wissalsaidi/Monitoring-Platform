<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\NotificationEvent;
use App\Enums\TargetType;
use App\Enums\WebhookType;
use App\Exceptions\WebhookDeliveryException;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Webhook;
use Illuminate\Support\Str;

/**
 * Bouton « Send test » (SCRUM-78) : envoi immédiat, hors queue, d'une alerte fictive dans le format du webhook.
 * Rien n'est enregistré (ni alerte, ni notification) ; le compteur d'échecs du webhook n'est pas touché.
 */
class WebhookTester
{
    public const TARGET_NAME = 'Sample target';

    public const EVENT = 'alert.test';

    public function __construct(private readonly WebhookSender $sender) {}

    /** @return array{ok: bool, status: ?int, ms: int, message: string} */
    public function send(Webhook $webhook): array
    {
        $alert = $this->sampleAlert($webhook);
        $deliveryId = (string) Str::uuid();
        // Le bouton « View alert » du message mène à la page des webhooks : l'alerte fictive n'a pas de page
        $payload = WebhookPayload::forWebhook($webhook, $alert, NotificationEvent::Opened, $deliveryId,
            url: route('teams.notifications.webhooks.index', $webhook->team));
        // Webhook générique : événement « alert.test », pour qu'un outil branché dessus n'ouvre pas de vrai ticket
        if ($webhook->type === WebhookType::Generic) {
            $payload = array_replace($payload, ['event' => self::EVENT]) + ['test' => true];
        }

        $start = hrtime(true);
        try {
            $status = $this->sender->send($webhook, self::EVENT, $payload, $deliveryId);
        } catch (WebhookDeliveryException $e) {
            return ['ok' => false, 'status' => null, 'ms' => $this->elapsed($start), 'message' => $e->getMessage()];
        }

        $ms = $this->elapsed($start);

        return ['ok' => true, 'status' => $status, 'ms' => $ms, 'message' => "HTTP {$status} in {$ms} ms."];
    }

    /** Alerte en mémoire, de la sévérité la plus grave à laquelle le webhook est abonné */
    private function sampleAlert(Webhook $webhook): Alert
    {
        $severity = $webhook->severities->sortByDesc(fn (AlertSeverity $s) => $s->rank())->first() ?? AlertSeverity::Critical;

        $target = (new MonitoringTarget)->forceFill(['id' => 0, 'team_id' => $webhook->team_id, 'name' => self::TARGET_NAME, 'type' => TargetType::Website]);
        $target->setRelation('team', $webhook->team);

        $alert = (new Alert)->forceFill([
            'id' => 0, 'severity' => $severity, 'status' => 'open', 'metric' => 'response_time', 'value' => 2504, 'threshold' => 2000,
            'message' => 'Test message from '.config('app.name').': no action needed.', 'started_at' => now(),
        ]);

        return $alert->setRelation('target', $target);
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1e6);
    }
}
