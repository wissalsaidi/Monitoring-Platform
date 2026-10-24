<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Qui prévenir, et par quel canal, pour un événement d'une alerte (SCRUM-52) : d'après les règles de la team de la
 * target (alert_rules). Destinataires : les membres de la team dont l'email est vérifié, quel que soit leur rôle.
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
     *
     * @return Collection<int, User>
     */
    public function recipients(Alert $alert, NotificationEvent $event): Collection
    {
        $team = $alert->target->team;

        if ($this->channelFor($team, $alert->severity, $event) !== NotificationChannel::Email) {
            return collect();
        }

        return $team->members()->whereNotNull('email_verified_at')->orderBy('users.id')->get();
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
        return $this->recipients($alert, $event)
            ->map(fn (User $user) => AlertNotification::firstOrCreate(
                [
                    'alert_id' => $alert->id,
                    'recipient' => $user->email,
                    'channel' => NotificationChannel::Email,
                    'event' => $event,
                ],
                ['user_id' => $user->id],
            ))
            ->filter(fn (AlertNotification $notification) => $notification->wasRecentlyCreated)
            ->values();
    }
}
