<?php

namespace App\Console\Commands;

use App\Exceptions\Zabbix\ZabbixException;
use App\Services\ZabbixService;
use Illuminate\Console\Command;

class ZabbixCheck extends Command
{
    protected $signature = 'zabbix:check';

    protected $description = "Vérifie la connexion et l'authentification à l'API Zabbix";

    public function handle(ZabbixService $zabbix): int
    {
        try {
            $this->components->twoColumnDetail('URL', config('services.zabbix.url'));
            $this->components->twoColumnDetail('Version Zabbix', $zabbix->version());

            $hosts = $zabbix->getHosts(['output' => ['hostid', 'name']]);
            $this->components->twoColumnDetail('Authentification (token)', '<fg=green>OK</>');
            $this->components->twoColumnDetail('Hosts visibles', (string) count($hosts));
        } catch (ZabbixException $e) {
            $this->components->error(class_basename($e).' : '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
