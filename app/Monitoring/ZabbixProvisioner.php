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

    public function __construct(protected readonly ZabbixService $zabbix) {}

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

    /**
     * Description, expression, priorité et tags de chaque trigger, indexés par niveau (tag "level"),
     * du plus grave au moins grave : chacun dépend du précédent (voir createChainedTriggers).
     *
     * @return array<string, array{description: string, expression: string, priority: int, tags: array}>
     */
    abstract protected function triggerDefinitions(MonitoringTarget $target): array;

    /**
     * Crée les triggers chaînés par dépendances, pour n'avoir qu'un seul problème actif par incident :
     * quand un trigger est en problème, Zabbix met en sourdine ceux qui en dépendent.
     */
    protected function createChainedTriggers(MonitoringTarget $target): void
    {
        $parentId = null;

        foreach ($this->triggerDefinitions($target) as $definition) {
            [$parentId] = $this->zabbix->createTriggers([
                ...$definition,
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
