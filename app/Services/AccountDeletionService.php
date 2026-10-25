<?php

namespace App\Services;

use App\Enums\TeamRole;
use App\Exceptions\GrafanaException;
use App\Exceptions\PlatformAdminException;
use App\Exceptions\TeamMembershipException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\AlertNotification;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Suppression d'un compte et de ses données personnelles (droit à l'effacement, RGPD-5, SCRUM-63).
 *
 * - teams dont l'utilisateur est le seul membre : supprimées avec leurs targets (hosts Zabbix compris) ;
 * - autres teams : l'adhésion est retirée, les targets restent (créateur et acquittements deviennent vides) ;
 * - journal d'audit : les entrées restent, sans auteur, sans adresse IP et sans l'email de l'utilisateur.
 */
class AccountDeletionService
{
    public const ANONYMOUS = 'deleted user';

    public function __construct(private MonitoringService $monitoring) {}

    /**
     * Teams qui empêchent la suppression : l'utilisateur en est le dernier Admin et elles ont d'autres membres.
     *
     * @return list<string> noms des teams
     */
    public function blockingTeams(User $user): array
    {
        return $user->teams()->get()
            ->filter(fn (Team $team) => $team->pivot->role === TeamRole::Admin
                && $team->members()->count() > 1
                && ! $team->members()->wherePivot('role', TeamRole::Admin->value)->whereKeyNot($user->id)->exists())
            ->pluck('name')->values()->all();
    }

    /**
     * @throws PlatformAdminException si l'utilisateur est le dernier Super Admin de la plateforme
     * @throws TeamMembershipException si l'utilisateur est le dernier Admin d'une team qui a d'autres membres
     * @throws ZabbixException si une target ne peut pas être supprimée de Zabbix (compte gardé)
     */
    public function delete(User $user): void
    {
        // La plateforme garde toujours un Super Admin (SCRUM-81)
        if (app(PlatformAdminService::class)->isLastSuperAdmin($user)) {
            throw new PlatformAdminException('You are the last Super Admin of the platform. Promote someone else first (php artisan platform:create-admin).');
        }

        if ($blocking = $this->blockingTeams($user)) {
            throw new TeamMembershipException(sprintf(
                'You are the last Admin of %s. Give the Admin role to another member, or remove the other members, before deleting your account.',
                implode(', ', $blocking),
            ));
        }

        // Teams sans autre membre : personne ne pourrait plus y accéder. Zabbix d'abord (hors transaction).
        $soloTeams = $user->teams()->get()->filter(fn (Team $team) => $team->members()->count() === 1);
        foreach ($soloTeams as $team) {
            $this->monitoring->deleteTeam($team);
        }

        // Compte Grafana (SCRUM-48) : facultatif, la synchro horaire ne le recrée pas (plus de teams)
        try {
            app(GrafanaAccessService::class)->deleteUser($user);
        } catch (GrafanaException $e) {
            Log::warning('Grafana account of a deleted user not removed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        DB::transaction(function () use ($user) {
            $email = $user->email;

            // Trace sans donnée personnelle dans chaque team quittée
            foreach ($user->teams()->get() as $team) {
                AuditLog::create([
                    'team_id' => $team->id,
                    'action' => 'member.account_deleted',
                    'subject_type' => $team->getMorphClass(),
                    'subject_id' => $team->id,
                    'details' => ['role' => $team->pivot->role->value],
                ]);
            }

            $this->anonymizeAuditLog($user, $email);
            // Historique des notifications (SCRUM-52) : gardé, sans l'adresse email
            AlertNotification::where('user_id', $user->id)->orWhere('recipient', $email)->update(['recipient' => self::ANONYMOUS]);

            TeamInvitation::where('email', $email)->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            // Adhésions supprimées en cascade ; created_by, acknowledged_by, invited_by et audit_logs.user_id passent à null
            $user->delete();
        });

        Log::info('User account deleted', ['user_id' => $user->id, 'solo_teams_deleted' => $soloTeams->count()]);
    }

    private function anonymizeAuditLog(User $user, string $email): void
    {
        AuditLog::where('user_id', $user->id)->update(['ip_address' => null]);

        // Email de l'utilisateur dans les détails (invitation, changement de rôle, retrait)
        AuditLog::where('details->email', $email)->get()->each(function (AuditLog $log) {
            $log->details = [...$log->details, 'email' => self::ANONYMOUS];
            $log->save();
        });
    }
}
