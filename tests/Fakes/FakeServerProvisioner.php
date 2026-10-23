<?php

namespace Tests\Fakes;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Monitoring\ZabbixProvisioner;

/**
 * Provisioner de test pour un type autre qu'API (SCRUM-66) : configuration dans settings (JSON),
 * interface agent sur le host, 2 triggers chaînés. Prouve que MonitoringService, le formulaire
 * et la synchro des alertes ne dépendent pas du type.
 */
class FakeServerProvisioner extends ZabbixProvisioner
{
    /** @var list<string> appels reçus : "create:<hostId>", "update:<targetId>" */
    public static array $calls = [];

    public static bool $failOnCreate = false;

    public function rules(): array
    {
        return [
            'settings.address' => ['required', 'string', 'max:255'],
            'settings.port' => ['required', 'integer', 'between:1,65535'],
            'settings.cpu_critical' => ['required', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        return ['settings.address.required' => 'The server address is required.'];
    }

    public function hostSettings(MonitoringTarget $target): array
    {
        return $this->agentInterface($target->setting('address'), $target->setting('port'));
    }

    public function create(MonitoringTarget $target, string $hostId): void
    {
        self::$calls[] = "create:$hostId";

        if (self::$failOnCreate) {
            throw new ZabbixException('Template not found.', 'template.get');
        }

        $this->createChainedTriggers($target);
    }

    public function update(MonitoringTarget $target): void
    {
        self::$calls[] = "update:{$target->id}";

        $this->updateTriggers($target);
    }

    public function statusItemKeys(): array
    {
        return ['availability' => 'agent.ping', 'response_time' => null];
    }

    public function threshold(MonitoringTarget $target, string $level): int|float|null
    {
        return $level === 'critical' ? $target->setting('cpu_critical') : null;
    }

    protected function triggerDefinitions(MonitoringTarget $target): array
    {
        $host = $target->zabbixHostName();

        return [
            'unavailable' => [
                'description' => "{$target->name} is unavailable",
                'expression' => "nodata(/$host/agent.ping,5m)=1",
                'priority' => self::PRIORITY_DISASTER,
                'tags' => $this->triggerTags($target, 'availability', 'unavailable'),
            ],
            'critical' => [
                'description' => "{$target->name} CPU > {$target->setting('cpu_critical')} %",
                'expression' => "last(/$host/system.cpu.util)>{$target->setting('cpu_critical')}",
                'priority' => self::PRIORITY_HIGH,
                'tags' => $this->triggerTags($target, 'cpu', 'critical'),
            ],
        ];
    }
}
