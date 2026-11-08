<?php

namespace App\Services;

use App\Enums\TeamRole;
use App\Exceptions\TeamMembershipException;
use App\Mail\TeamInvitationMail;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Gestion des membres d'une team (SCRUM-59) : invitation, changement de rôle, retrait.
 * Règle : une team garde toujours au moins un Admin. Chaque action est tracée dans l'audit.
 */
class TeamMemberService
{
    /** Invite (ou réinvite) une adresse : une invitation en attente pour le même email est remplacée. */
    public function invite(Team $team, string $email, TeamRole $role, ?User $by): TeamInvitation
    {
        $email = Str::lower(trim($email));

        if ($team->members()->where('email', $email)->exists()) {
            throw new TeamMembershipException('This person is already a member of the team.');
        }

        $invitation = TeamInvitation::updateOrCreate(
            ['team_id' => $team->id, 'email' => $email],
            ['role' => $role, 'invited_by' => $by?->id, 'expires_at' => now()->addDays(TeamInvitation::VALID_DAYS)],
        );

        Mail::to($email)->queue(new TeamInvitationMail($invitation));
        AuditLog::record('member.invite', $by, $team, [
            'email' => $email,
            'role' => $role->value,
            'resent' => ! $invitation->wasRecentlyCreated,
            ...($by === null ? ['via' => 'console'] : []),   // platform:invite (SCRUM-83)
        ]);

        return $invitation;
    }

    public function cancelInvitation(TeamInvitation $invitation, User $by): void
    {
        $invitation->delete();
        AuditLog::record('member.invite_cancel', $by, $invitation->team, ['email' => $invitation->email]);
    }

    /** L'invité (connecté avec le bon email) rejoint la team. */
    public function accept(TeamInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user) {
            if (! $user->belongsToTeam($invitation->team)) {
                $invitation->team->members()->attach($user, ['role' => $invitation->role]);
            }

            $invitation->delete();
            AuditLog::record('member.join', $user, $invitation->team, ['role' => $invitation->role->value]);
        });
    }

    /** Ajout direct par un Super Admin (SCRUM-84) : le compte existe déjà, pas besoin d'invitation */
    public function add(Team $team, User $user, TeamRole $role, User $by): void
    {
        DB::transaction(function () use ($team, $user, $role, $by) {
            if (DB::table('team_members')->where('team_id', $team->id)->lockForUpdate()->where('user_id', $user->id)->exists()) {
                throw new TeamMembershipException('This person is already a member of the team.');
            }

            $team->members()->attach($user, ['role' => $role]);
            TeamInvitation::where('team_id', $team->id)->where('email', $user->email)->delete();
            AuditLog::record('member.add', $by, $team, [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $role->value,
            ]);
        });
    }

    /**
     * @param  ?User  $by  null : synchronisation des groupes de l'annuaire (SCRUM-92)
     * @param  array<string, string>  $context  ajouté au détail de l'audit (ex. via: sso_group, group)
     */
    public function changeRole(Team $team, User $member, TeamRole $role, ?User $by, array $context = []): void
    {
        DB::transaction(function () use ($team, $member, $role, $by, $context) {
            $old = $this->lockedRole($team, $member);

            if ($old === $role) {
                return;
            }

            if ($old === TeamRole::Admin) {
                $this->ensureAnotherAdmin($team, $member);
            }

            $team->members()->updateExistingPivot($member->id, ['role' => $role]);
            AuditLog::record('member.role_change', $by, $team, [
                'user_id' => $member->id,
                'email' => $member->email,
                'old' => $old->value,
                'new' => $role->value,
                ...$context,
            ]);
        });
    }

    /** @param  ?User  $by  null : synchronisation des groupes de l'annuaire (SCRUM-92) */
    public function remove(Team $team, User $member, ?User $by, array $context = []): void
    {
        DB::transaction(function () use ($team, $member, $by, $context) {
            $role = $this->lockedRole($team, $member);

            if ($role === TeamRole::Admin) {
                $this->ensureAnotherAdmin($team, $member);
            }

            $team->members()->detach($member->id);
            AuditLog::record('member.remove', $by, $team, [
                'user_id' => $member->id,
                'email' => $member->email,
                'role' => $role->value,
                ...$context,
            ]);
        });

        // Il ne voit plus les dashboards Grafana de la team (SCRUM-48)
        app(GrafanaAccessService::class)->revoke($member, $team);
    }

    /**
     * Rôle actuel du membre. Les lignes des membres de la team sont verrouillées jusqu'à la fin
     * de la transaction : deux Admins qui se retirent en même temps ne peuvent pas vider la team.
     */
    private function lockedRole(Team $team, User $member): TeamRole
    {
        $rows = DB::table('team_members')->where('team_id', $team->id)->lockForUpdate()->pluck('role', 'user_id');

        if (! $rows->has($member->id)) {
            throw new TeamMembershipException('This person is not a member of the team.');
        }

        return TeamRole::from($rows[$member->id]);
    }

    private function ensureAnotherAdmin(Team $team, User $member): void
    {
        $otherAdmins = $team->members()
            ->wherePivot('role', TeamRole::Admin->value)
            ->whereKeyNot($member->id)
            ->active()                                  // un Admin désactivé n'administre plus rien (SCRUM-84)
            ->exists();

        if (! $otherAdmins) {
            throw TeamMembershipException::lastAdmin();
        }
    }
}
