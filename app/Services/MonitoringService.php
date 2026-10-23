<?php

namespace App\Services;

use App\Enums\TargetType;
use App\Exceptions\UnsupportedTargetTypeException;
use App\Exceptions\Zabbix\ZabbixApiException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\ProvisionerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestration métier des targets : base Laravel + configuration Zabbix (SCRUM-38).
 *
 * Partie commune à tous les types (SCRUM-66) : host Zabbix, host group de la team, transaction, retour arrière,
 * suppression. Les checks et triggers propres au type sont créés par son provisioner (App\Monitoring\Provisioners).
 */
class MonitoringService
{
    public function __construct(
        private readonly ZabbixService $zabbix,
        private readonly ProvisionerRegistry $provisioners,
    ) {}

    /**
     * Crée une target et sa supervision Zabbix. Tout ou rien : si Zabbix échoue,
     * la target n'est pas enregistrée et rien ne reste dans Zabbix.
     *
     * @param  array{name: string, check_interval: int, description?: ?string}  $data  champs communs + champs propres au type
     *
     * @throws ZabbixException
     * @throws UnsupportedTargetTypeException
     */
    public function createTarget(Team $team, ?User $creator, TargetType $type, array $data): MonitoringTarget
    {
        $this->provisioners->for($type);

        return DB::transaction(function () use ($team, $creator, $type, $data) {
            $target = $team->monitoringTargets()->create([
                ...$data,
                'type' => $type,
                'created_by' => $creator?->id,
            ]);
            $target->setRelation('team', $team);

            $hostId = $this->provision($target);

            // La transaction annule la target, mais pas le host : le supprimer si l'enregistrement échoue
            try {
                $target->update(['zabbix_host_id' => $hostId]);
            } catch (Throwable $e) {
                $this->rollback($target, $hostId);

                throw $e;
            }

            return $target;
        });
    }

    /**
     * Crée dans Zabbix le host (dans le host group de la team), puis les checks et triggers du type.
     * En cas d'échec, le host déjà créé est supprimé (ce qui supprime aussi ses checks et triggers).
     *
     * @return string id du host Zabbix
     *
     * @throws ZabbixException
     */
    public function provision(MonitoringTarget $target): string
    {
        $provisioner = $this->provisioners->for($target->type);
        $hostId = null;

        try {
            $hostId = $this->zabbix->createHost([
                'host' => $target->zabbixHostName(),
                'name' => $target->zabbixVisibleName(),
                'groups' => [['groupid' => $this->hostGroupFor($target->team)]],
                'tags' => [['tag' => 'target_id', 'value' => (string) $target->id]],
                ...$provisioner->hostSettings($target),
            ]);

            $provisioner->create($target, $hostId);

            // Dans le try : un log impossible à écrire ne doit pas laisser un host orphelin dans Zabbix
            Log::info('Monitoring target provisioned in Zabbix', ['target_id' => $target->id, 'type' => $target->type->value, 'zabbix_host_id' => $hostId]);
        } catch (Throwable $e) {
            if ($hostId !== null) {
                $this->rollback($target, $hostId);
            }

            throw $e;
        }

        return $hostId;
    }

    /**
     * Modifie une target (SCRUM-35) et répercute dans Zabbix les champs que son provisioner déclare.
     * Tout ou rien : si Zabbix échoue, la base n'est pas modifiée et Zabbix est remis dans son état précédent.
     *
     * @return array<string, array{0: mixed, 1: mixed}> champs modifiés : [ancienne valeur, nouvelle valeur]
     *
     * @throws ZabbixException
     */
    public function updateTarget(MonitoringTarget $target, array $data): array
    {
        $before = clone $target;

        return DB::transaction(function () use ($target, $before, $data) {
            $target->fill($data);
            $changes = collect($target->getDirty())
                ->map(fn ($value, $field) => [$before->getAttribute($field), $target->getAttribute($field)])
                ->all();
            $target->save();

            if ($target->zabbix_host_id !== null && array_intersect(array_keys($changes), $target->provisioner()->zabbixFields())) {
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
     * Supprime une target (SCRUM-35) : d'abord son host Zabbix (avec checks, triggers et historique, quel que soit le type),
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
     * Supprime la supervision Zabbix d'une target (le host, donc ses checks et triggers).
     */
    public function deprovision(MonitoringTarget $target): void
    {
        if ($target->zabbix_host_id === null) {
            return;
        }

        $this->zabbix->deleteHost($target->zabbix_host_id);
        $target->update(['zabbix_host_id' => null]);
    }

    /** Aligne le host, puis les checks et triggers du type, sur la target. */
    private function syncToZabbix(MonitoringTarget $target): void
    {
        $this->zabbix->updateHost($target->zabbix_host_id, ['name' => $target->zabbixVisibleName()]);

        $target->provisioner()->update($target);
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
}
