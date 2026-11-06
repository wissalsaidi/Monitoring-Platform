<?php

namespace App\Services;

use App\Exceptions\GrafanaException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Accès des utilisateurs de la plateforme à Grafana (SCRUM-48) : chacun ne voit que les hosts de ses teams.
 *
 * Par team :
 * - un groupe d'utilisateurs Zabbix avec le droit de lecture sur le host group de la team, et rien d'autre ;
 * - un utilisateur Zabbix de ce groupe (accès à l'interface Zabbix désactivé). Son mot de passe aléatoire n'est
 *   enregistré nulle part sauf dans la datasource Grafana ; pour refaire la datasource, il est changé ;
 * - une organisation Grafana, avec l'app Zabbix, une datasource « Zabbix » qui se connecte avec cet utilisateur, et
 *   le dashboard générique (SCRUM-46).
 *
 * Par utilisateur de la plateforme : un compte Grafana « user-<id> », Viewer des organisations de ses teams et d'aucune
 * autre (pas l'organisation principale, où l'admin a une datasource qui voit tout). La connexion passe par l'auth proxy
 * (nginx demande à Laravel qui est connecté) : aucun mot de passe Grafana pour les utilisateurs.
 */
class GrafanaAccessService
{
    public const ZABBIX_PLUGIN = 'alexanderzobnin-zabbix-app';

    public const DATASOURCE_TYPE = 'alexanderzobnin-zabbix-datasource';

    public const DATASOURCE_UID = 'zabbix';

    /** Zabbix : lecture seule (hostgroup_rights.permission) */
    private const ZABBIX_READ = 2;

    /** Zabbix : pas d'accès à l'interface web (usergroup.gui_access), seulement à l'API */
    private const ZABBIX_GUI_DISABLED = 3;

    public function __construct(
        private readonly GrafanaApi $grafana,
        private readonly ZabbixService $zabbix,
        private readonly MonitoringService $monitoring,
    ) {}

    /** Login Grafana d'un utilisateur de la plateforme (header X-WEBAUTH-USER de l'auth proxy) */
    public static function login(User $user): string
    {
        return "user-{$user->id}";
    }

    /**
     * Avant d'ouvrir le dashboard d'une team : organisation de la team prête, compte Grafana de l'utilisateur créé,
     * Viewer de cette organisation. Rapide quand tout existe déjà (un seul appel à Grafana).
     *
     * @return int id de l'organisation Grafana de la team
     *
     * @throws GrafanaException|ZabbixException
     */
    public function grant(User $user, Team $team): int
    {
        if ($team->grafana_org_id === null || $team->zabbix_user_id === null) {
            $this->syncTeam($team);
        }

        if ($user->grafana_user_id === null) {
            $this->createAccount($user, $team->grafana_org_id);
        }

        $this->grafana->setOrgRole($team->grafana_org_id, self::login($user), $user->grafana_user_id, GrafanaApi::ROLE_VIEWER);

        return $team->grafana_org_id;
    }

    /**
     * Vérifie et répare tout ce qui appartient à la team (synchro complète : commande grafana:sync, chaque heure),
     * puis remet le dashboard à jour depuis son fichier.
     *
     * @throws GrafanaException|ZabbixException
     */
    public function syncTeam(Team $team): void
    {
        $hostGroupId = $this->monitoring->hostGroupFor($team);

        // Groupe Zabbix : lecture seule sur le host group de la team
        if ($team->zabbix_usrgrp_id === null || $this->zabbix->getUserGroups(['output' => ['usrgrpid'], 'usrgrpids' => [$team->zabbix_usrgrp_id]]) === []) {
            $team->update(['zabbix_usrgrp_id' => $this->zabbix->createUserGroup([
                'name' => "Grafana viewers: team #{$team->id}",
                'gui_access' => self::ZABBIX_GUI_DISABLED,
                'hostgroup_rights' => [['id' => $hostGroupId, 'permission' => self::ZABBIX_READ]],
            ])]);
        }

        // Utilisateur Zabbix de la datasource : nouveau → son mot de passe sert à (re)faire la datasource
        $password = null;
        if ($team->zabbix_user_id === null || $this->zabbix->getUsers(['output' => ['userid'], 'userids' => [$team->zabbix_user_id]]) === []) {
            $password = self::password();
            $team->update(['zabbix_user_id' => $this->zabbix->createUser([
                'username' => $this->zabbixUsername($team),
                'name' => 'Grafana',
                'surname' => "Team #{$team->id}",
                'passwd' => $password,
                'roleid' => (string) config('services.grafana.zabbix_role_id'),
                'usrgrps' => [['usrgrpid' => $team->zabbix_usrgrp_id]],
            ])]);
        }

        // Organisation Grafana de la team
        if ($team->grafana_org_id === null || ! $this->grafana->orgExists($team->grafana_org_id)) {
            $team->update(['grafana_org_id' => $this->grafana->createOrg(self::orgName($team))]);
        }
        $orgId = $team->grafana_org_id;

        $this->grafana->enablePlugin($orgId, self::ZABBIX_PLUGIN);

        if ($password !== null || ! $this->grafana->dataSourceExists($orgId, self::DATASOURCE_UID)) {
            if ($password === null) {
                // Datasource disparue : son mot de passe est perdu, on en donne un nouveau à l'utilisateur Zabbix
                $password = self::password();
                $this->zabbix->updateUser($team->zabbix_user_id, ['passwd' => $password]);
            }

            $dataSource = $this->dataSource($team, $password);
            $this->grafana->dataSourceExists($orgId, self::DATASOURCE_UID)
                ? $this->grafana->updateDataSource($orgId, self::DATASOURCE_UID, $dataSource)
                : $this->grafana->createDataSource($orgId, $dataSource);
        }

        $this->grafana->importDashboard($orgId, $this->dashboard());

        Log::info('Grafana access of the team synced', ['team_id' => $team->id, 'grafana_org_id' => $orgId]);
    }

    /**
     * Synchro complète d'un compte : Viewer des organisations de ses teams, retiré de toutes les autres.
     * Compte supprimé dans Grafana entre-temps : recréé.
     *
     * @throws GrafanaException|ZabbixException
     */
    public function syncUser(User $user): void
    {
        // Compte désactivé (SCRUM-84) : comme sans team, il est retiré de toutes les organisations
        $teams = $user->isActive() ? $user->teams()->get() : collect();

        foreach ($teams as $team) {
            if ($team->grafana_org_id === null) {
                $this->syncTeam($team);
            }
        }

        $wanted = $teams->pluck('grafana_org_id')->filter()->map(fn ($id) => (int) $id)->values();

        if ($user->grafana_user_id !== null && ! $this->grafana->userExists($user->grafana_user_id)) {
            $user->forceFill(['grafana_user_id' => null])->save();
        }
        if ($user->grafana_user_id === null) {
            if ($wanted->isEmpty()) {
                return;     // pas de team : pas de compte
            }
            $this->createAccount($user, $wanted->first());
        }

        foreach ($wanted as $orgId) {
            $this->grafana->setOrgRole($orgId, self::login($user), $user->grafana_user_id, GrafanaApi::ROLE_VIEWER);
        }

        foreach (array_keys($this->grafana->userOrgs($user->grafana_user_id)) as $orgId) {
            if (! $wanted->contains($orgId)) {
                $this->grafana->removeFromOrg($orgId, $user->grafana_user_id);
            }
        }
    }

    /**
     * Membre retiré d'une team : il perd tout de suite l'accès à son organisation Grafana. Un échec est journalisé ;
     * la synchro horaire (grafana:sync) le rattrape.
     */
    public function revoke(User $user, Team $team): void
    {
        // Ids relus en base : l'objet reçu peut dater d'avant la création de l'organisation ou du compte
        $user = $user->fresh() ?? $user;
        $team = $team->fresh() ?? $team;

        if ($user->grafana_user_id === null || $team->grafana_org_id === null) {
            return;
        }

        try {
            $this->grafana->removeFromOrg($team->grafana_org_id, $user->grafana_user_id);
        } catch (GrafanaException $e) {
            Log::warning('Grafana access not revoked: grafana:sync will retry', ['team_id' => $team->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Team supprimée : son organisation Grafana, puis son utilisateur et son groupe Zabbix (avant son host group).
     *
     * @throws GrafanaException|ZabbixException
     */
    public function deleteTeam(Team $team): void
    {
        if ($team->grafana_org_id !== null) {
            $this->grafana->deleteOrg($team->grafana_org_id);
        }
        if ($team->zabbix_user_id !== null && $this->zabbix->getUsers(['output' => ['userid'], 'userids' => [$team->zabbix_user_id]]) !== []) {
            $this->zabbix->deleteUsers($team->zabbix_user_id);
        }
        if ($team->zabbix_usrgrp_id !== null && $this->zabbix->getUserGroups(['output' => ['usrgrpid'], 'usrgrpids' => [$team->zabbix_usrgrp_id]]) !== []) {
            $this->zabbix->deleteUserGroups($team->zabbix_usrgrp_id);
        }

        $team->update(['grafana_org_id' => null, 'zabbix_user_id' => null, 'zabbix_usrgrp_id' => null]);
    }

    /** Nom de l'organisation Grafana d'une team ; l'id la rend unique même si deux teams ont le même nom */
    public static function orgName(Team $team): string
    {
        return "{$team->name} #{$team->id}";
    }

    /**
     * Team renommée (SCRUM-85) : son organisation Grafana suit.
     *
     * @throws GrafanaException
     */
    public function renameTeam(Team $team): void
    {
        if ($team->grafana_org_id !== null) {
            $this->grafana->renameOrg($team->grafana_org_id, self::orgName($team));
        }
    }

    /** Compte supprimé (SCRUM-63) : son compte Grafana aussi. */
    public function deleteUser(User $user): void
    {
        if ($user->grafana_user_id !== null) {
            $this->grafana->deleteUser($user->grafana_user_id);
        }
    }

    /** Nouveau compte, membre de cette organisation seulement (Grafana peut l'avoir rattaché à une autre par défaut). */
    private function createAccount(User $user, int $orgId): void
    {
        $user->forceFill([
            'grafana_user_id' => $this->grafana->createUser(self::login($user), $user->name, $user->email, self::password(), $orgId),
        ])->save();

        foreach (array_keys($this->grafana->userOrgs($user->grafana_user_id)) as $otherOrgId) {
            if ($otherOrgId !== $orgId) {
                $this->grafana->removeFromOrg($otherOrgId, $user->grafana_user_id);
            }
        }
    }

    private function zabbixUsername(Team $team): string
    {
        return "grafana-team-{$team->id}";
    }

    /** Datasource Zabbix de l'organisation de la team : même uid que dans l'organisation principale (le dashboard s'y réfère). */
    private function dataSource(Team $team, string $password): array
    {
        return [
            'name' => 'Zabbix',
            'uid' => self::DATASOURCE_UID,
            'type' => self::DATASOURCE_TYPE,
            'access' => 'proxy',
            'url' => config('services.grafana.zabbix_api_url'),
            'isDefault' => true,
            'jsonData' => ['authType' => 'userLogin', 'username' => $this->zabbixUsername($team), 'trends' => true],
            'secureJsonData' => ['password' => $password],
        ];
    }

    /** Dashboard générique versionné (SCRUM-46) */
    private function dashboard(): array
    {
        $path = config('services.grafana.dashboard_file');
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($json)) {
            throw new GrafanaException("Grafana dashboard file not readable: {$path}");
        }

        return $json;
    }

    /** 32 caractères aléatoires : lettres, chiffres et symboles (règles de mot de passe de Zabbix) */
    private static function password(): string
    {
        return Str::password(32);
    }
}
