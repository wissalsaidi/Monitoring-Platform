<?php

namespace App\Jobs;

use App\Exceptions\Zabbix\ZabbixException;
use App\Services\AlertService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Récupère les problèmes Zabbix et met à jour alertes et statuts des targets (SCRUM-40).
 * Planifié chaque minute (routes/console.php), exécuté par le worker de la queue Redis.
 */
class SyncZabbixEvents implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Pas de nouvel essai : la synchro suivante passe une minute plus tard. */
    public int $tries = 1;

    public int $timeout = 50;

    /** Une seule synchro en file à la fois, même si le worker a pris du retard. */
    public int $uniqueFor = 60;

    public function handle(AlertService $alerts): void
    {
        $log = Log::channel('zabbix');
        $log->info('Zabbix synchronization started');

        try {
            $stats = $alerts->syncFromZabbix();
        } catch (ZabbixException $e) {
            // Zabbix indisponible : on garde les derniers statuts connus, la prochaine synchro réessaiera.
            $log->critical('Monitoring synchronization failed', ['exception' => class_basename($e), 'error' => $e->getMessage()]);

            return;
        }

        $log->info("{$stats['problems']} events retrieved", $stats);
    }
}
