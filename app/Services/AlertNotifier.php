<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\NotificationEvent;
use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications des alertes (SCRUM-53, résolution en SCRUM-54) : appelé par la synchro quand elle crée ou résout une alerte. Enregistre les
 * notifications à envoyer selon les règles de la team (NotificationRouter), puis met un job d'envoi par notification
 * dans la queue. Une erreur ici ne bloque jamais la synchro.
 */
class AlertNotifier
{
    public function __construct(private readonly NotificationRouter $router) {}

    /** @return int nombre de notifications mises en queue */
    public function alertOpened(Alert $alert): int
    {
        // Problème déjà résolu quand la synchro le découvre (ex. Zabbix relu après une coupure) : rien à annoncer
        if ($alert->status === AlertStatus::Resolved) {
            return 0;
        }

        return $this->queue($alert, NotificationEvent::Opened);
    }

    /**
     * Alerte passée en RESOLVED (SCRUM-54) : seuls ceux qui ont été prévenus de son ouverture sont prévenus de sa
     * résolution (NotificationRouter).
     *
     * @return int nombre de notifications mises en queue
     */
    public function alertResolved(Alert $alert): int
    {
        return $alert->status === AlertStatus::Resolved ? $this->queue($alert, NotificationEvent::Resolved) : 0;
    }

    private function queue(Alert $alert, NotificationEvent $event): int
    {
        try {
            $planned = $this->router->plan($alert, $event);
        } catch (Throwable $e) {
            Log::error('Alert notifications not planned', ['alert_id' => $alert->id, 'event' => $event->value, 'error' => $e->getMessage()]);

            return 0;
        }

        foreach ($planned as $notification) {
            SendAlertNotification::dispatch($notification->id);
        }

        if ($planned->isNotEmpty()) {
            Log::info('Alert notifications queued', ['alert_id' => $alert->id, 'event' => $event->value, 'count' => $planned->count()]);
        }

        return $planned->count();
    }
}
