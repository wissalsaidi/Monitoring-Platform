<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\Team;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Collection;

/**
 * Qui prévenir, et par quel canal, pour un événement d'une alerte (SCRUM-52) : d'après les règles de la team de la
 * target (alert_rules). Destinataires : les membres de la team dont l'email est vérifié, quel que soit leur rôle,
 * plus les webhooks de la team abonnés à la sévérité et à l'événement (SCRUM-75).
 */
class NotificationRouter
{
    /** Canal de la règle de la team ; sans règle, dashboard seulement (rien n'est envoyé). */
    public function channelFor(Team $team, AlertSeverity $severity, NotificationEvent $event): NotificationChannel
    {
        // value() applique le cast du modèle : NotificationChannel, ou null sans règle
        return AlertRule::where('team_id', $team->id)
            ->where('severity', $severity->value)
            ->where('event', $event->value)
            ->value('channel') ?? NotificationChannel::Dashboard;
    }

    /**
     * Destinataires d'un email pour cet événement : aucun si la règle dit « dashboard seulement ».
     * Résolution (SCRUM-54) : seulement les membres prévenus de l'ouverture de cette alerte (notification envoyée ou
     * en cours d'envoi) : jamais de « résolu » pour une alerte dont on n'a pas annoncé l'ouverture.
     *
     * @return Collection<int, User>
     */
    public function recipients(Alert $alert, NotificationEvent $event): Collection
    {
        $team = $alert->target->team;

        if ($this->channelFor($team, $alert->severity, $event) !== NotificationChannel::Email) {
            return collect();
        }

        return $team->members()
            ->whereNotNull('email_verified_at')
            ->active()                                  // compte désactivé : plus aucune notification (SCRUM-84)
            ->when($event === NotificationEvent::Resolved, fn ($query) => $query->whereIn('users.id', AlertNotification::query()
                ->select('user_id')
                ->where('alert_id', $alert->id)
                ->where('event', NotificationEvent::Opened->value)
                ->where('status', '!=', NotificationStatus::Failed->value)
                ->whereNotNull('user_id')))
            ->orderBy('users.id')
            ->get();
    }

    /**
     * Notifications à envoyer pour cet événement : une ligne « pending » par destinataire. Une notification déjà
     * enregistrée (même alerte, destinataire, canal, événement) n'est jamais recréée : seules les nouvelles sont
     * renvoyées, à envoyer.
     *
     * @return Collection<int, AlertNotification>
     */
    public function plan(Alert $alert, NotificationEvent $event): Collection
    {
        $emails = $this->recipients($alert, $event)
            ->map(fn (User $user) => AlertNotification::firstOrCreate(
                [
                    'alert_id' => $alert->id,
                    'recipient' => $user->email,
                    'channel' => NotificationChannel::Email,
                    'event' => $event,
                ],
                ['user_id' => $user->id],
            ));

        // Webhooks (SCRUM-75) : une notification par webhook abonné ; le destinataire est le nom du webhook
        $webhooks = $this->webhooks($alert, $event)
            ->map(fn (Webhook $webhook) => AlertNotification::firstOrCreate(
                [
                    'alert_id' => $alert->id,
                    'recipient' => $webhook->name,
                    'channel' => NotificationChannel::Webhook,
                    'event' => $event,
                ],
                ['webhook_id' => $webhook->id],
            ));

        return $emails->concat($webhooks)
            ->filter(fn (AlertNotification $notification) => $notification->wasRecentlyCreated)
            ->values();
    }

    /**
     * Webhooks actifs de la team abonnés à la sévérité et à l'événement (SCRUM-75), indépendamment des règles email.
     * Résolution : seulement les webhooks qui ont reçu l'ouverture (ou vont la recevoir), comme pour les emails.
     *
     * @return Collection<int, Webhook>
     */
    public function webhooks(Alert $alert, NotificationEvent $event): Collection
    {
        $webhooks = $alert->target->team->webhooks()->active()->orderBy('id')->get()
            ->filter(fn (Webhook $webhook) => $webhook->subscribedTo($alert->severity, $event));

        if ($event === NotificationEvent::Resolved) {
            $opened = AlertNotification::where('alert_id', $alert->id)
                ->where('channel', NotificationChannel::Webhook->value)
                ->where('event', NotificationEvent::Opened->value)
                ->where('status', '!=', NotificationStatus::Failed->value)
                ->pluck('webhook_id');
            $webhooks = $webhooks->filter(fn (Webhook $webhook) => $opened->contains($webhook->id));
        }

        return $webhooks->values();
    }
}
