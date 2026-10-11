<?php

namespace App\Services;

use App\Enums\TargetType;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
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

    /** Priorités Zabbix utilisées (le mapping vers la plateforme est l'objet de SCRUM-39). */
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

            $this->zabbix->createWebScenario([
                'name' => self::SCENARIO,
                'hostid' => $hostId,
                'delay' => "{$target->check_interval}s",
                'steps' => [[
                    'no' => 1,
                    'name' => self::STEP,
                    'url' => $target->url,
                    'status_codes' => '200-299',
                    'timeout' => self::STEP_TIMEOUT,
                    'follow_redirects' => 1,
                ]],
            ]);

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
        $host = $target->zabbixHostName();
        $time = sprintf('/%s/web.test.time[%s,%s,resp]', $host, self::SCENARIO, self::STEP);
        $fail = sprintf('/%s/web.test.fail[%s]', $host, self::SCENARIO);

        [$unavailableId] = $this->zabbix->createTriggers([
            'description' => "{$target->name} is unavailable",
            'expression' => "last($fail)<>0",
            'priority' => self::PRIORITY_DISASTER,
            'tags' => $this->triggerTags($target, 'availability', 'unavailable'),
        ]);

        [$criticalId] = $this->zabbix->createTriggers([
            'description' => "{$target->name} response time > {$target->critical_threshold_ms} ms",
            'expression' => "last($time)>".self::seconds($target->critical_threshold_ms),
            'priority' => self::PRIORITY_HIGH,
            'tags' => $this->triggerTags($target, 'response_time', 'critical'),
            'dependencies' => [['triggerid' => $unavailableId]],
        ]);

        $this->zabbix->createTriggers([
            'description' => "{$target->name} response time > {$target->warning_threshold_ms} ms",
            'expression' => "last($time)>".self::seconds($target->warning_threshold_ms),
            'priority' => self::PRIORITY_WARNING,
            'tags' => $this->triggerTags($target, 'response_time', 'warning'),
            'dependencies' => [['triggerid' => $criticalId]],
        ]);
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
