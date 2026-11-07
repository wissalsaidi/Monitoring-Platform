<?php

namespace App\Services;

use App\Enums\NotificationEvent;
use App\Enums\WebhookType;
use App\Models\Alert;
use App\Models\Webhook;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

/**
 * Corps JSON d'un webhook générique (SCRUM-76), versionné : un outil qui le lit peut compter sur ses champs.
 * Ajouter un champ ne change pas la version ; en retirer ou en renommer un la fait passer à 2.
 */
class WebhookPayload
{
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public static function forAlert(Alert $alert, NotificationEvent $event, string $deliveryId, ?float $peak = null): array
    {
        $target = $alert->target;
        $iso = fn (?Carbon $date) => $date?->copy()->utc()->toIso8601ZuluString();

        return [
            'version' => self::VERSION,
            'event' => self::eventName($event),
            'delivery_id' => $deliveryId,
            'sent_at' => $iso(now()),
            'alert' => [
                'id' => $alert->id,
                'severity' => $alert->severity->value,
                'status' => $alert->status->value,
                'message' => $alert->message,
                'metric' => $alert->metric,
                'value' => $alert->value,
                'threshold' => $alert->threshold,
                'peak_value' => $peak ?? $alert->peak_value,
                'started_at' => $iso($alert->started_at),
                'resolved_at' => $iso($alert->resolved_at),
                'url' => route('targets.show', [$target, 'tab' => $event === NotificationEvent::Resolved ? 'history' : 'alerts']),
            ],
            'target' => [
                'id' => $target->id,
                'name' => $target->name,
                'type' => $target->type->value,
            ],
            'team' => [
                'id' => $target->team->id,
                'name' => $target->team->name,
            ],
        ];
    }

    /**
     * Corps à envoyer selon le type du webhook (SCRUM-77) : JSON générique, message Slack ou Adaptive Card Teams.
     *
     * @return array<string, mixed>
     */
    public static function forWebhook(Webhook $webhook, Alert $alert, NotificationEvent $event, string $deliveryId, ?float $peak = null): array
    {
        return match ($webhook->type) {
            WebhookType::Generic => self::forAlert($alert, $event, $deliveryId, $peak),
            WebhookType::Slack => WebhookMessage::slack($alert, $event, $peak),
            WebhookType::Teams => WebhookMessage::teams($alert, $event, $peak),
        };
    }

    /** « alert.opened » ou « alert.resolved » (en-tête X-Platform-Event et champ event) */
    public static function eventName(NotificationEvent $event): string
    {
        return 'alert.'.$event->value;
    }

    /** Identifiant de livraison stable d'une notification : le même à chaque nouvel essai (idempotence chez le receveur) */
    public static function deliveryId(int $notificationId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, rtrim((string) config('app.url'), '/').'/notifications/'.$notificationId)->toString();
    }
}
