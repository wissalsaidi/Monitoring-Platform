<?php

namespace App\Monitoring\Provisioners;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Monitoring\ZabbixProvisioner;
use App\Rules\PublicUrl;

/**
 * Check d'API (SCRUM-38, déplacé de MonitoringService en SCRUM-66 sans changement de comportement) :
 * web scenario « api-check » à une étape, et 3 triggers chaînés indisponible ← critical ← warning.
 * Configuration en colonnes : url, warning_threshold_ms, critical_threshold_ms.
 */
class ApiProvisioner extends ZabbixProvisioner
{
    /** Noms du web scenario et de son étape : ils apparaissent dans les clés d'items Zabbix. */
    public const SCENARIO = 'api-check';

    public const STEP = 'request';

    private const STEP_TIMEOUT = '15s';

    public function rules(): array
    {
        return [
            // Protection SSRF : pas d'adresse locale ou interne (SCRUM-57)
            'url' => ['required', 'string', 'max:2048', 'url:http,https', 'bail', new PublicUrl],
            'warning_threshold_ms' => ['required', 'integer', 'min:1', 'max:'.MonitoringTarget::MAX_THRESHOLD_MS],
            'critical_threshold_ms' => ['required', 'integer', 'gt:warning_threshold_ms', 'max:'.MonitoringTarget::MAX_THRESHOLD_MS],
        ];
    }

    public function messages(): array
    {
        return [
            'critical_threshold_ms.gt' => 'The critical threshold must be greater than the warning threshold.',
        ];
    }

    public function attributes(): array
    {
        return [
            'url' => 'URL',
            'warning_threshold_ms' => 'warning threshold',
            'critical_threshold_ms' => 'critical threshold',
        ];
    }

    public function create(MonitoringTarget $target, string $hostId): void
    {
        $this->zabbix->createWebScenario(['name' => self::SCENARIO, 'hostid' => $hostId, ...$this->scenarioSettings($target)]);

        $this->createChainedTriggers($target);
    }

    public function update(MonitoringTarget $target): void
    {
        // L'étape existante doit être désignée par son httpstepid : sans lui, Zabbix supprime et recrée l'étape,
        // donc l'item web.test.time, ses triggers et son historique.
        $scenario = $this->zabbix->getWebScenarios([
            'output' => ['httptestid'],
            'hostids' => [$target->zabbix_host_id],
            'filter' => ['name' => self::SCENARIO],
            'selectSteps' => ['httpstepid'],
        ])[0] ?? throw new ZabbixException('Web scenario not found in Zabbix.', 'httptest.get');
        $this->zabbix->updateWebScenario($scenario['httptestid'], $this->scenarioSettings($target, $scenario['steps'][0]['httpstepid'] ?? null));

        $this->updateTriggers($target);
    }

    public function statusItemKeys(): array
    {
        return ['availability' => self::failKey(), 'response_time' => self::responseTimeKey()];
    }

    public function threshold(MonitoringTarget $target, string $level): int|float|null
    {
        return match ($level) {
            'warning' => $target->warning_threshold_ms,
            'critical' => $target->critical_threshold_ms,
            default => null,
        };
    }

    /** Clé de l'item « échec » du web scenario : 0 si la dernière exécution a réussi. */
    public static function failKey(): string
    {
        return sprintf('web.test.fail[%s]', self::SCENARIO);
    }

    /** Clé de l'item « temps de réponse » de l'étape, en secondes. */
    public static function responseTimeKey(): string
    {
        return sprintf('web.test.time[%s,%s,resp]', self::SCENARIO, self::STEP);
    }

    protected function triggerDefinitions(MonitoringTarget $target): array
    {
        $host = $target->zabbixHostName();
        $time = '/'.$host.'/'.self::responseTimeKey();
        $fail = '/'.$host.'/'.self::failKey();

        return [
            'unavailable' => [
                'description' => "{$target->name} is unavailable",
                'expression' => "last($fail)<>0",
                'priority' => self::PRIORITY_DISASTER,
                'tags' => $this->triggerTags($target, 'availability', 'unavailable'),
            ],
            'critical' => [
                'description' => "{$target->name} response time > {$target->critical_threshold_ms} ms",
                'expression' => "last($time)>".self::seconds($target->critical_threshold_ms),
                'priority' => self::PRIORITY_HIGH,
                'tags' => $this->triggerTags($target, 'response_time', 'critical'),
            ],
            'warning' => [
                'description' => "{$target->name} response time > {$target->warning_threshold_ms} ms",
                'expression' => "last($time)>".self::seconds($target->warning_threshold_ms),
                'priority' => self::PRIORITY_WARNING,
                'tags' => $this->triggerTags($target, 'response_time', 'warning'),
            ],
        ];
    }

    /** Intervalle et étape du web scenario (URL, codes attendus, timeout) ; $stepId pour modifier l'étape existante */
    private function scenarioSettings(MonitoringTarget $target, ?string $stepId = null): array
    {
        return [
            'delay' => "{$target->check_interval}s",
            'steps' => [[
                ...($stepId !== null ? ['httpstepid' => $stepId] : []),
                'no' => 1,
                'name' => self::STEP,
                'url' => $target->url,
                'status_codes' => '200-299',
                'timeout' => self::STEP_TIMEOUT,
                'follow_redirects' => 1,
            ]],
        ];
    }
}
