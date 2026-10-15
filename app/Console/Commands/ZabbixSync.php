<?php

namespace App\Console\Commands;

use App\Exceptions\Zabbix\ZabbixException;
use App\Services\AlertService;
use Illuminate\Console\Command;

/**
 * Lance la synchro Zabbix immédiatement, sans attendre le scheduler (démo, diagnostic).
 */
class ZabbixSync extends Command
{
    protected $signature = 'zabbix:sync';

    protected $description = 'Synchronize Zabbix problems into alerts and update target statuses';

    public function handle(AlertService $alerts): int
    {
        try {
            $stats = $alerts->syncFromZabbix();
        } catch (ZabbixException $e) {
            $this->error($e->userMessage());

            return self::FAILURE;
        }

        $this->table(['Problems', 'New alerts', 'Resolved', 'Target statuses changed'], [array_values($stats)]);

        return self::SUCCESS;
    }
}
