<?php

namespace App\Services;

use App\Enums\TeamRole;
use App\Exceptions\GrafanaException;
use App\Exceptions\TeamMembershipException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Teams créées, renommées et supprimées par un Super Admin (SCRUM-85), plus jamais à l'inscription.
 * Zabbix (host group) et Grafana (organisation) suivent. Un échec côté Zabbix ou Grafana à la création ou au
 * renommage ne bloque pas : la team existe, et la synchro (création à la demande, grafana:sync) rattrape.
 */
class TeamAdminService
{
    public function __construct(
        private MonitoringService $monitoring,
        private GrafanaAccessService $grafana,
        private TeamMemberService $members,
        private ZabbixService $zabbix,
    ) {}

    /**
     * Le premier Admin est un compte existant (ajouté tout de suite) ou une adresse (invitée comme Admin).
     *
     * @return array{0: Team, 1: list<string>} la team, et les avertissements à afficher (Zabbix ou Grafana pas encore prêts)
     */
    public function create(string $name, ?User $admin, ?string $inviteEmail, User $by): array
    {
        $team = DB::transaction(function () use ($name, $admin, $by, $inviteEmail) {
            $team = Team::create(['name' => $name]);
            if ($admin !== null) {
                $team->members()->attach($admin, ['role' => TeamRole::Admin]);
            }
            AuditLog::record('team.create', $by, $team, array_filter([
                'name' => $team->name,
                'admin' => $admin?->email,
                'invited_admin' => $inviteEmail,
            ]));

            return $team;
        });

        if ($inviteEmail !== null) {
            $this->members->invite($team, $inviteEmail, TeamRole::Admin, $by);
        }

        $warnings = [];
        try {
            $this->monitoring->hostGroupFor($team);
        } catch (ZabbixException $e) {
            $warnings[] = 'The Zabbix host group could not be created yet: it will be created with the first target.';
            Log::warning('Zabbix host group of a new team not created', ['team_id' => $team->id, 'error' => $e->getMessage()]);
        }
        try {
            $this->grafana->syncTeam($team);
        } catch (GrafanaException|ZabbixException $e) {
            $warnings[] = 'The Grafana organization could not be created yet: grafana:sync will create it.';
            Log::warning('Grafana access of a new team not created', ['team_id' => $team->id, 'error' => $e->getMessage()]);
        }

        return [$team, $warnings];
    }

    /** @return list<string> avertissements (nom pas encore repris par Zabbix ou Grafana) */
    public function rename(Team $team, string $name, User $by): array
    {
        $old = $team->name;
        if ($old === $name) {
            return [];
        }

        $team->update(['name' => $name]);
        AuditLog::record('team.rename', $by, $team, ['changes' => ['name' => [$old, $name]]]);

        $warnings = [];
        if ($team->zabbix_hostgroup_id !== null) {
            try {
                $this->zabbix->renameHostGroup($team->zabbix_hostgroup_id, $team->zabbixHostGroupName());
            } catch (ZabbixException $e) {
                $warnings[] = 'Zabbix still shows the old name of the host group. Rename the team again to retry.';
                Log::warning('Zabbix host group not renamed', ['team_id' => $team->id, 'error' => $e->getMessage()]);
            }
        }
        try {
            $this->grafana->renameTeam($team);
        } catch (GrafanaException $e) {
            $warnings[] = 'Grafana still shows the old name of the organization. Rename the team again to retry.';
            Log::warning('Grafana organization not renamed', ['team_id' => $team->id, 'error' => $e->getMessage()]);
        }

        return $warnings;
    }

    /**
     * Suppression définitive : targets retirées de Zabbix, organisation Grafana et host group supprimés,
     * membres détachés (leurs comptes restent). L'entrée d'audit n'est pas liée à la team, pour survivre.
     *
     * @throws TeamMembershipException si le nom tapé ne correspond pas
     * @throws ZabbixException si une target ne peut pas être retirée de Zabbix (rien n'est supprimé de plus)
     */
    public function delete(Team $team, string $confirmation, User $by): void
    {
        if ($confirmation !== $team->name) {
            throw new TeamMembershipException('Type the exact name of the team to confirm.');
        }

        $details = [
            'name' => $team->name,
            'team_id' => $team->id,
            'targets' => $team->monitoringTargets()->count(),
            'members' => $team->members()->count(),
        ];

        $this->monitoring->deleteTeam($team);
        AuditLog::record('team.delete', $by, null, $details);
    }
}
