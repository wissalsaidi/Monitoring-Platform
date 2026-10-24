<?php

namespace App\Monitoring;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Services\ZabbixService;
use Illuminate\Support\Arr;

/**
 * Base des provisioners : triggers chaînés, tags lus par la synchro, mise à jour des triggers par niveau.
 */
abstract class ZabbixProvisioner implements TargetProvisioner
{
    /** Priorités Zabbix utilisées : WARNING, HIGH et CRITICAL côté plateforme (voir AlertSeverity). */
    public const PRIORITY_WARNING = 2;

    public const PRIORITY_HIGH = 4;

    public const PRIORITY_DISASTER = 5;

    /** Types de valeur Zabbix des séries de graphique (history.get, paramètre history) */
    public const VALUE_TYPE_FLOAT = 0;

    public const VALUE_TYPE_UNSIGNED = 3;

    /** Type d'interface Zabbix « agent » (hostinterface.type) : zabbix-server interroge l'agent en mode passif. */
    public const INTERFACE_AGENT = 1;

    public function __construct(protected readonly ZabbixService $zabbix) {}

    public function updateRules(): array
    {
        return $this->rules();
    }

    public function messages(): array
    {
        return [];
    }

    public function attributes(): array
    {
        return [];
    }

    /** Par défaut : le nom, l'intervalle et tous les champs propres au type (settings.* → settings). */
    public function zabbixFields(): array
    {
        $fields = array_map(fn (string $field) => explode('.', $field)[0], array_keys($this->rules()));

        return array_values(array_unique(['name', 'check_interval', ...$fields]));
    }

    public function hostSettings(MonitoringTarget $target): array
    {
        return [];
    }

    public function metricItemKeys(): array
    {
        return [];
    }

    public function checkSummary(MonitoringTarget $target): array
    {
        return [];
    }

    /** Par défaut : le temps de réponse, si le type en a un (Zabbix le mesure en secondes, affiché en ms). */
    public function chartSeries(): array
    {
        $key = $this->statusItemKeys()['response_time'] ?? null;

        return $key === null ? [] : [
            'response_time' => ['label' => 'Response time', 'key' => $key, 'unit' => 'ms', 'scale' => 1000, 'value_type' => self::VALUE_TYPE_FLOAT],
        ];
    }

    public function chartThresholds(MonitoringTarget $target, string $series): array
    {
        return $series === 'response_time'
            ? ['warning' => $target->warning_threshold_ms, 'critical' => $target->critical_threshold_ms]
            : ['warning' => null, 'critical' => null];
    }

    /**
     * Interface agent d'un host, à renvoyer par hostSettings() (SCRUM-67).
     * Par défaut l'agent de la plateforme (config monitoring.agent) ; une adresse IP est déclarée en IP, un nom en DNS.
     *
     * @return array{interfaces: list<array<string, int|string>>}
     */
    protected function agentInterface(?string $address = null, ?int $port = null): array
    {
        $address ??= config('monitoring.agent.host');
        $isIp = filter_var($address, FILTER_VALIDATE_IP) !== false;

        return [
            'interfaces' => [[
                'type' => self::INTERFACE_AGENT,
                'main' => 1,
                'useip' => $isIp ? 1 : 0,
                'ip' => $isIp ? $address : '',
                'dns' => $isIp ? '' : $address,
                'port' => (string) ($port ?? config('monitoring.agent.port')),
            ]],
        ];
    }

    /**
     * Interface agent d'un host déjà créé (son interfaceid sert à créer les items d'agent).
     *
     * @return array{interfaceid: string}
     *
     * @throws ZabbixException
     */
    protected function agentInterfaceOf(string $hostId): array
    {
        return collect($this->zabbix->getHosts(['output' => ['hostid'], 'hostids' => [$hostId], 'selectInterfaces' => ['interfaceid', 'type']])[0]['interfaces'] ?? [])
            ->firstWhere('type', (string) self::INTERFACE_AGENT)
            ?? throw new ZabbixException('Agent interface of the host not found in Zabbix.', 'host.get');
    }

    /** Modification d'une target : nouvelle adresse / nouveau port de l'agent sur l'interface existante. */
    protected function updateAgentInterface(string $hostId, ?string $address, ?int $port): void
    {
        $interface = $this->agentInterfaceOf($hostId);
        $settings = $this->agentInterface($address, $port)['interfaces'][0];

        $this->zabbix->updateHostInterface($interface['interfaceid'], Arr::only($settings, ['useip', 'ip', 'dns', 'port']));
    }

    /**
     * Description, expression, priorité et tags de chaque trigger, indexés par niveau (tag "level"),
     * du plus grave au moins grave : chacun dépend du précédent de la même chaîne (voir createChainedTriggers).
     * Clé facultative "chain" : triggers indépendants des autres (ex. le certificat d'un site web, SCRUM-68).
     *
     * @return array<string, array{description: string, expression: string, priority: int, tags: array, chain?: string}>
     */
    abstract protected function triggerDefinitions(MonitoringTarget $target): array;

    /**
     * Crée les triggers chaînés par dépendances, pour n'avoir qu'un seul problème actif par incident :
     * quand un trigger est en problème, Zabbix met en sourdine ceux qui en dépendent. Une chaîne par clé "chain".
     *
     * @param  array<string, array>|null  $definitions  par défaut, tous les triggers de la target
     */
    protected function createChainedTriggers(MonitoringTarget $target, ?array $definitions = null): void
    {
        $parents = [];

        foreach ($definitions ?? $this->triggerDefinitions($target) as $definition) {
            $chain = $definition['chain'] ?? 'main';
            $parentId = $parents[$chain] ?? null;

            [$parents[$chain]] = $this->zabbix->createTriggers([
                ...Arr::except($definition, 'chain'),
                ...($parentId !== null ? ['dependencies' => [['triggerid' => $parentId]]] : []),
            ]);
        }
    }

    /** Met à jour description et expression des triggers existants, retrouvés par leur tag "level". */
    protected function updateTriggers(MonitoringTarget $target): void
    {
        $definitions = $this->triggerDefinitions($target);
        $updates = [];

        foreach ($this->zabbix->getTriggers(['output' => ['triggerid'], 'hostids' => [$target->zabbix_host_id], 'selectTags' => ['tag', 'value']]) as $trigger) {
            $level = collect($trigger['tags'] ?? [])->firstWhere('tag', 'level')['value'] ?? null;
            if (isset($definitions[$level])) {
                $updates[] = ['triggerid' => $trigger['triggerid'], ...Arr::only($definitions[$level], ['description', 'expression'])];
            }
        }

        // trigger.get ne voit plus un trigger dont l'item a disparu : ne pas modifier à moitié
        if (count($updates) !== count($definitions)) {
            throw new ZabbixException('Triggers of the target not found in Zabbix.', 'trigger.get');
        }

        $this->zabbix->updateTriggers($updates);
    }

    /** Tags lus par la synchro des problèmes (SCRUM-40) pour retrouver target, métrique et seuil. */
    protected function triggerTags(MonitoringTarget $target, string $metric, string $level): array
    {
        return [
            ['tag' => 'target_id', 'value' => (string) $target->id],
            ['tag' => 'metric', 'value' => $metric],
            ['tag' => 'level', 'value' => $level],
        ];
    }

    /** 500 ms → "0.5", 1000 ms → "1" : Zabbix mesure les temps de réponse en secondes. */
    protected static function seconds(int $milliseconds): string
    {
        return rtrim(rtrim(sprintf('%.3F', $milliseconds / 1000), '0'), '.');
    }
}
