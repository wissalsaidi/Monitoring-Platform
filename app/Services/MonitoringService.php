<?php

namespace App\Services;

use App\Enums\TargetType;
use App\Exceptions\Zabbix\ZabbixApiException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestration métier des targets : base Laravel + configuration Zabbix (SCRUM-38).
 */
class MonitoringService
{
    /** Noms du web scenario et de son étape : ils apparaissent dans les clés d'items Zabbix. */
    public const SCENARIO = 'api-check';

    public const STEP = 'request';

    /** Priorités Zabbix utilisées : WARNING, HIGH et CRITICAL côté plateforme (voir AlertSeverity). */
    public const PRIORITY_WARNING = 2;

    public const PRIORITY_HIGH = 4;

    public const PRIORITY_DISASTER = 5;

    private const STEP_TIMEOUT = '15s';

    public function __construct(private readonly ZabbixService $zabbix) {}

    /**
     * Crée une target API et sa supervision Zabbix. Tout ou rien : si Zabbix échoue,
     * la target n'est pas enregistrée et rien ne reste dans Zabbix.
     *
     * @param  array{name: string, url: string, check_interval: int, warning_threshold_ms: int, critical_threshold_ms: int, description?: ?string}  $data
     *
     * @throws ZabbixException
     */
    public function createApiTarget(Team $team, ?User $creator, array $data): MonitoringTarget
    {
        return DB::transaction(function () use ($team, $creator, $data) {
            $target = $team->monitoringTargets()->create([
                ...$data,
                'type' => TargetType::Api,
                'created_by' => $creator?->id,
            ]);
            $target->setRelation('team', $team);

            $target->update(['zabbix_host_id' => $this->provision($target)]);

            return $target;
        });
    }

    /**
     * Crée dans Zabbix : host (dans le host group de la team), web scenario et triggers.
     * En cas d'échec, le host déjà créé est supprimé (ce qui supprime aussi son scenario et ses triggers).
     *
     * @return string id du host Zabbix
     *
     * @throws ZabbixException
     */
    public function provision(MonitoringTarget $target): string
    {
        $hostId = null;

        try {
            $hostId = $this->zabbix->createHost([
                'host' => $target->zabbixHostName(),
                'name' => $target->zabbixVisibleName(),
                'groups' => [['groupid' => $this->hostGroupFor($target->team)]],
                'tags' => [['tag' => 'target_id', 'value' => (string) $target->id]],
            ]);

            $this->zabbix->createWebScenario(['name' => self::SCENARIO, 'hostid' => $hostId, ...$this->scenarioSettings($target)]);

            $this->createTriggers($target);
        } catch (Throwable $e) {
            if ($hostId !== null) {
                $this->rollback($target, $hostId);
            }

            throw $e;
        }

        Log::info('Monitoring target provisioned in Zabbix', ['target_id' => $target->id, 'zabbix_host_id' => $hostId]);

        return $hostId;
    }

    /** Champs dont un changement doit être répercuté dans Zabbix */
    private const ZABBIX_FIELDS = ['name', 'url', 'check_interval', 'warning_threshold_ms', 'critical_threshold_ms'];

    /**
     * Modifie une target (SCRUM-35) et répercute dans Zabbix le nom, l'URL, l'intervalle et les seuils.
     * Tout ou rien : si Zabbix échoue, la base n'est pas modifiée et Zabbix est remis dans son état précédent.
     *
     * @param  array{name: string, url: string, check_interval: int, warning_threshold_ms: int, critical_threshold_ms: int, description?: ?string}  $data
     * @return array<string, array{0: mixed, 1: mixed}> champs modifiés : [ancienne valeur, nouvelle valeur]
     *
     * @throws ZabbixException
     */
    public function updateApiTarget(MonitoringTarget $target, array $data): array
    {
        $before = clone $target;

        return DB::transaction(function () use ($target, $before, $data) {
            $target->fill($data);
            $changes = collect($target->getDirty())
                ->map(fn ($value, $field) => [$before->getAttribute($field), $target->getAttribute($field)])
                ->all();
            $target->save();

            if ($target->zabbix_host_id !== null && array_intersect(array_keys($changes), self::ZABBIX_FIELDS)) {
                try {
                    $this->syncToZabbix($target);
                } catch (Throwable $e) {
                    $this->restoreZabbix($before);

                    throw $e;
                }

                Log::info('Monitoring target updated in Zabbix', ['target_id' => $target->id, 'fields' => array_keys($changes)]);
            }

            return $changes;
        });
    }

    /**
     * Supprime une target (SCRUM-35) : d'abord son host Zabbix (avec scenario, triggers et historique),
     * puis la target et ses alertes. Un host déjà absent de Zabbix n'empêche pas la suppression.
     *
     * @throws ZabbixException
     */
    public function deleteTarget(MonitoringTarget $target): void
    {
        if ($target->zabbix_host_id !== null) {
            try {
                $this->zabbix->deleteHost($target->zabbix_host_id);
            } catch (ZabbixApiException $e) {
                if ($this->zabbix->getHosts(['output' => ['hostid'], 'hostids' => [$target->zabbix_host_id]]) !== []) {
                    throw $e;
                }

                Log::warning('Zabbix host already gone when deleting the target', ['target_id' => $target->id, 'zabbix_host_id' => $target->zabbix_host_id]);
            }
        }

        $target->delete();
        Log::info('Monitoring target deleted', ['target_id' => $target->id, 'zabbix_host_id' => $target->zabbix_host_id]);
    }

    /**
     * Supprime une team, ses targets (hosts Zabbix compris) et son host group Zabbix
     * (suppression de compte du dernier membre, SCRUM-63).
     *
     * @throws ZabbixException si un host ne peut pas être supprimé : la team est alors gardée
     */
    public function deleteTeam(Team $team): void
    {
        foreach ($team->monitoringTargets()->get() as $target) {
            $this->deleteTarget($target);
        }

        if ($team->zabbix_hostgroup_id !== null) {
            try {
                $this->zabbix->deleteHostGroup($team->zabbix_hostgroup_id);
            } catch (ZabbixException $e) {
                // Un host group vide oublié dans Zabbix ne bloque pas la suppression
                Log::warning('Zabbix host group not deleted with its team', ['team_id' => $team->id, 'groupid' => $team->zabbix_hostgroup_id, 'error' => $e->getMessage()]);
            }
        }

        $team->delete();
        Log::info('Team deleted', ['team_id' => $team->id]);
    }

    /**
     * Supprime la supervision Zabbix d'une target (host, scenario et triggers).
     */
    public function deprovision(MonitoringTarget $target): void
    {
        if ($target->zabbix_host_id === null) {
            return;
        }

        $this->zabbix->deleteHost($target->zabbix_host_id);
        $target->update(['zabbix_host_id' => null]);
    }

    /** Aligne le host, le web scenario et les triggers Zabbix sur la target. */
    private function syncToZabbix(MonitoringTarget $target): void
    {
        $hostId = $target->zabbix_host_id;

        $this->zabbix->updateHost($hostId, ['name' => $target->zabbixVisibleName()]);

        // L'étape existante doit être désignée par son httpstepid : sans lui, Zabbix supprime et recrée l'étape,
        // donc l'item web.test.time, ses triggers et son historique.
        $scenario = $this->zabbix->getWebScenarios([
            'output' => ['httptestid'],
            'hostids' => [$hostId],
            'filter' => ['name' => self::SCENARIO],
            'selectSteps' => ['httpstepid'],
        ])[0] ?? throw new ZabbixException('Web scenario not found in Zabbix.', 'httptest.get');
        $this->zabbix->updateWebScenario($scenario['httptestid'], $this->scenarioSettings($target, $scenario['steps'][0]['httpstepid'] ?? null));

        $definitions = $this->triggerDefinitions($target);
        $updates = [];
        foreach ($this->zabbix->getTriggers(['output' => ['triggerid'], 'hostids' => [$hostId], 'selectTags' => ['tag', 'value']]) as $trigger) {
            $level = collect($trigger['tags'] ?? [])->firstWhere('tag', 'level')['value'] ?? null;
            if (isset($definitions[$level])) {
                $updates[] = ['triggerid' => $trigger['triggerid'], ...Arr::only($definitions[$level], ['description', 'expression'])];
            }
        }

        if (count($updates) !== count($definitions)) {
            throw new ZabbixException('Triggers of the target not found in Zabbix.', 'trigger.get');
        }

        $this->zabbix->updateTriggers($updates);
    }

    /** Après un échec de modification : remet Zabbix dans l'état d'avant (au mieux, sans masquer l'erreur d'origine). */
    private function restoreZabbix(MonitoringTarget $before): void
    {
        try {
            $this->syncToZabbix($before);
        } catch (Throwable $e) {
            Log::error('Zabbix restore failed after a target update error: Zabbix may differ from the platform', [
                'target_id' => $before->id,
                'error' => $e->getMessage(),
            ]);
        }
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

    /**
     * Host group de la team : réutilisé s'il existe déjà dans Zabbix (même nom), créé sinon.
     */
    private function hostGroupFor(Team $team): string
    {
        if ($team->zabbix_hostgroup_id !== null) {
            return $team->zabbix_hostgroup_id;
        }

        $name = $team->zabbixHostGroupName();
        $existing = $this->zabbix->getHostGroups(['output' => ['groupid'], 'filter' => ['name' => [$name]]]);
        $groupId = $existing[0]['groupid'] ?? $this->zabbix->createHostGroup($name);

        $team->update(['zabbix_hostgroup_id' => $groupId]);

        return $groupId;
    }

    /**
     * Trois triggers chaînés par dépendances, pour n'avoir qu'un seul problème actif par incident :
     * indisponible  ←  critical (temps > seuil critical)  ←  warning (temps > seuil warning).
     * Quand un trigger parent est en problème, Zabbix met ses dépendants en sourdine.
     */
    private function createTriggers(MonitoringTarget $target): void
    {
        $definitions = $this->triggerDefinitions($target);

        [$unavailableId] = $this->zabbix->createTriggers($definitions['unavailable']);
        [$criticalId] = $this->zabbix->createTriggers([...$definitions['critical'], 'dependencies' => [['triggerid' => $unavailableId]]]);
        $this->zabbix->createTriggers([...$definitions['warning'], 'dependencies' => [['triggerid' => $criticalId]]]);
    }

    /**
     * Description, expression, priorité et tags de chaque trigger, indexés par niveau (tag "level").
     *
     * @return array<string, array>
     */
    private function triggerDefinitions(MonitoringTarget $target): array
    {
        $host = $target->zabbixHostName();
        $time = sprintf('/%s/web.test.time[%s,%s,resp]', $host, self::SCENARIO, self::STEP);
        $fail = sprintf('/%s/web.test.fail[%s]', $host, self::SCENARIO);

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

    /** Tags lus plus tard par la synchro des problèmes (SCRUM-40) pour retrouver target, métrique et seuil. */
    private function triggerTags(MonitoringTarget $target, string $metric, string $level): array
    {
        return [
            ['tag' => 'target_id', 'value' => (string) $target->id],
            ['tag' => 'metric', 'value' => $metric],
            ['tag' => 'level', 'value' => $level],
        ];
    }

    private function rollback(MonitoringTarget $target, string $hostId): void
    {
        try {
            $this->zabbix->deleteHost($hostId);
            Log::warning('Zabbix provisioning rolled back', ['target_id' => $target->id, 'zabbix_host_id' => $hostId]);
        } catch (ZabbixException $e) {
            // On garde l'erreur d'origine ; le host orphelin est signalé pour nettoyage manuel.
            Log::error('Zabbix provisioning rollback failed: orphan host left in Zabbix', [
                'target_id' => $target->id,
                'zabbix_host_id' => $hostId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** 500 ms → "0.5", 1000 ms → "1" : Zabbix mesure les temps de réponse en secondes. */
    private static function seconds(int $milliseconds): string
    {
        return rtrim(rtrim(sprintf('%.3F', $milliseconds / 1000), '0'), '.');
    }
}
