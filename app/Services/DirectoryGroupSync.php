<?php

namespace App\Services;

use App\Enums\TeamRole;
use App\Exceptions\TeamMembershipException;
use App\Mail\DirectorySyncBlockedMail;
use App\Models\AuditLog;
use App\Models\DirectoryGroupMapping;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Groupes de l'annuaire → teams et rôles (SCRUM-92), appliqués à chaque connexion SSO.
 *
 * - Sans correspondance définie, rien ne change : teams et rôles restent gérés dans la plateforme.
 * - Jeton sans claim de groupes (OIDC_GROUPS_CLAIM) : rien ne change non plus, pour ne rien retirer sur une erreur
 *   de configuration du fournisseur.
 * - Un groupe correspond : la personne est ajoutée à la team, ou son rôle est changé ; plusieurs groupes pour une même
 *   team donnent le rôle le plus élevé.
 * - Seules les appartenances venues de l'annuaire (team_members.directory_group) sont retirées quand le groupe disparaît,
 *   jamais celles ajoutées à la main.
 * - Jamais le rôle Super Admin. Une team ne perd jamais son dernier Admin actif : refus, audit et email aux Super Admin.
 */
class DirectoryGroupSync
{
    private const RANK = ['viewer' => 1, 'manager' => 2, 'admin' => 3];

    public function __construct(private TeamMemberService $members) {}

    /** @param  array<string, mixed>  $claims  claims vérifiés du jeton d'identité */
    public function apply(User $user, array $claims): void
    {
        $claim = $claims[config('oidc.groups_claim')] ?? null;
        if (! is_array($claim)) {
            return;
        }
        $mappings = DirectoryGroupMapping::with('team')->get();
        $current = $user->teams()->get()->keyBy('id');
        // Aucune correspondance, et rien qui vienne de l'annuaire : la fonctionnalité n'est pas utilisée. Sinon, on
        // continue même sans correspondance : la dernière supprimée retire les appartenances qu'elle avait données.
        if ($mappings->isEmpty() && $current->every(fn (Team $team) => $team->pivot->directory_group === null)) {
            return;
        }

        $groups = self::normalize($claim);
        $desired = $this->desiredMemberships($mappings, $groups);

        foreach ($desired as $teamId => ['team' => $team, 'role' => $role, 'group' => $group]) {
            $membership = $current->get($teamId);
            $context = ['via' => 'sso_group', 'group' => $group];

            if ($membership === null) {
                $this->join($team, $user, $role, $group);

                continue;
            }

            if ($membership->pivot->role !== $role) {
                try {
                    $this->members->changeRole($team, $user, $role, null, $context);
                } catch (TeamMembershipException $e) {
                    $this->blocked($team, $user, $group, $e->getMessage());

                    continue;
                }
            }
            // Appartenance venue de l'annuaire : on garde le groupe qui la donne maintenant (affiché sur la fiche)
            if ($membership->pivot->directory_group !== null && $membership->pivot->directory_group !== $group) {
                $team->members()->updateExistingPivot($user->id, ['directory_group' => $group]);
            }
        }

        foreach ($current as $teamId => $team) {
            $group = $team->pivot->directory_group;
            if ($group === null || $desired->has($teamId)) {
                continue;
            }

            try {
                $this->members->remove($team, $user, null, ['via' => 'sso_group', 'group' => $group]);
            } catch (TeamMembershipException $e) {
                $this->blocked($team, $user, $group, $e->getMessage());
            }
        }
    }

    /**
     * Groupes du jeton : chaînes, sans le « / » de tête du chemin Keycloak (« /monitoring-admins »).
     *
     * @return list<string>
     */
    public static function normalize(array $claim): array
    {
        return collect($claim)->filter(fn ($group) => is_string($group) && trim($group) !== '')
            ->map(fn (string $group) => ltrim(trim($group), '/'))->unique()->values()->all();
    }

    /**
     * Rôle le plus élevé par team parmi les groupes de la personne.
     *
     * @param  Collection<int, DirectoryGroupMapping>  $mappings
     * @param  list<string>  $groups
     * @return Collection<int, array{team: Team, role: TeamRole, group: string}>
     */
    private function desiredMemberships(Collection $mappings, array $groups): Collection
    {
        $desired = collect();
        foreach ($mappings as $mapping) {
            if (! in_array(ltrim($mapping->group, '/'), $groups, true)) {
                continue;
            }
            $best = $desired->get($mapping->team_id);
            if ($best === null || self::RANK[$mapping->role->value] > self::RANK[$best['role']->value]) {
                $desired->put($mapping->team_id, ['team' => $mapping->team, 'role' => $mapping->role, 'group' => $mapping->group]);
            }
        }

        return $desired;
    }

    private function join(Team $team, User $user, TeamRole $role, string $group): void
    {
        DB::transaction(function () use ($team, $user, $role, $group) {
            $team->members()->attach($user->id, ['role' => $role, 'directory_group' => $group]);
            // Une invitation en attente pour cette team n'a plus d'objet
            TeamInvitation::where('team_id', $team->id)->where('email', $user->email)->delete();
            AuditLog::record('member.add', null, $team, [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $role->value,
                'via' => 'sso_group',
                'group' => $group,
            ]);
        });
    }

    /**
     * Synchronisation refusée (dernier Admin actif) : audit, et email aux Super Admin actifs au plus une fois par jour
     * pour la même personne et la même team.
     */
    private function blocked(Team $team, User $user, string $group, string $reason): void
    {
        $recent = AuditLog::where('action', 'member.sync_blocked')->where('team_id', $team->id)
            ->where('created_at', '>=', now()->subDay())->get()
            ->contains(fn (AuditLog $log) => ($log->details['user_id'] ?? null) === $user->id);

        AuditLog::record('member.sync_blocked', null, $team, [
            'user_id' => $user->id,
            'email' => $user->email,
            'group' => $group,
            'reason' => $reason,
            'via' => 'sso_group',
        ]);

        if (! $recent) {
            User::superAdmins()->active()->each(
                fn (User $admin) => Mail::to($admin)->queue(new DirectorySyncBlockedMail($team, $user, $group, $reason))
            );
        }
    }
}
