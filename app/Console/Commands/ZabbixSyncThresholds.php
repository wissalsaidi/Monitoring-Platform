<?php

namespace App\Console\Commands;

use App\Models\MonitoringTarget;
use Illuminate\Console\Command;

/**
 * Items de seuil des targets (SCRUM-46) : à lancer une fois après la mise à jour, pour les targets créées avant
 * (ensuite, créer ou modifier une target les tient à jour), ou après un échec signalé dans le log zabbix.
 */
class ZabbixSyncThresholds extends Command
{
    protected $signature = 'zabbix:sync-thresholds';

    protected $description = 'Crée ou met à jour dans Zabbix les items de seuil lus par le dashboard Grafana';

    public function handle(): int
    {
        $failed = 0;
        $targets = MonitoringTarget::whereNotNull('zabbix_host_id')->orderBy('id')->get();

        foreach ($targets as $target) {
            if (! $target->provisioner()->syncThresholdItems($target, $target->zabbix_host_id)) {
                $failed++;
                $this->components->error("{$target->name} (#{$target->id}) : refused by Zabbix, see the zabbix log");
            }
        }

        $this->components->info(sprintf('%d target(s) synced, %d failed.', $targets->count() - $failed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
