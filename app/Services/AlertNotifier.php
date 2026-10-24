<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\NotificationEvent;
use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications des alertes (SCRUM-53) : appelé par la synchro quand elle crée une alerte. Enregistre les
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

        try {
            $planned = $this->router->plan($alert, NotificationEvent::Opened);
        } catch (Throwable $e) {
            Log::error('Alert notifications not planned', ['alert_id' => $alert->id, 'error' => $e->getMessage()]);

            return 0;
        }

        foreach ($planned as $notification) {
            SendAlertNotification::dispatch($notification->id);
        }

        if ($planned->isNotEmpty()) {
            Log::info('Alert notifications queued', ['alert_id' => $alert->id, 'event' => 'opened', 'count' => $planned->count()]);
        }

        return $planned->count();
    }
}
