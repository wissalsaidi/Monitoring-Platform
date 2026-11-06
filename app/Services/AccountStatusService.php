<?php

namespace App\Services;

use App\Enums\TeamRole;
use App\Exceptions\AccountStatusException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Désactivation, réactivation et réinitialisation forcée du mot de passe (SCRUM-84), par un Super Admin.
 * Un compte désactivé est gardé (historique, audit, adhésions) mais ne peut plus rien faire : connexion refusée,
 * sessions fermées, accès Grafana retiré, plus aucune notification.
 */
class AccountStatusService
{
    public function __construct(private GrafanaAccessService $grafana) {}

    /** @throws AccountStatusException */
    public function deactivate(User $user, User $by): void
    {
        if ($user->is($by)) {
            throw new AccountStatusException('You cannot deactivate your own account.');
        }

        DB::transaction(function () use ($user, $by) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $user->isActive()) {
                throw new AccountStatusException("{$user->email} is already deactivated.");
            }

            if ($user->isSuperAdmin() && User::superAdmins()->active()->lockForUpdate()->count() === 1) {
                throw new AccountStatusException('The platform must keep at least one active Super Admin.');
            }

            // Le seul Admin d'une team : la team ne serait plus administrable
            $orphaned = $user->teams()->wherePivot('role', TeamRole::Admin->value)->get()
                ->filter(fn ($team) => ! $team->members()->active()->wherePivot('role', TeamRole::Admin->value)->whereKeyNot($user->id)->exists());
            if ($orphaned->isNotEmpty()) {
                throw new AccountStatusException("{$user->name} is the only Admin of ".$orphaned->pluck('name')->join(', ').'. Give the Admin role to another member first.');
            }

            $user->forceFill(['deactivated_at' => now(), 'remember_token' => Str::random(60)])->save();
            $this->closeSessions($user);
            AuditLog::record('user.deactivate', $by, $user, ['email' => $user->email]);
        });

        // Plus d'accès aux dashboards Grafana de ses teams (un échec est rattrapé par grafana:sync)
        foreach ($user->teams()->get() as $team) {
            $this->grafana->revoke($user, $team);
        }
    }

    /** @throws AccountStatusException */
    public function reactivate(User $user, User $by): void
    {
        if ($user->isActive()) {
            throw new AccountStatusException("{$user->email} is already active.");
        }

        $user->forceFill(['deactivated_at' => null])->save();
        AuditLog::record('user.reactivate', $by, $user, ['email' => $user->email]);

        try {
            $this->grafana->syncUser($user);
        } catch (\Throwable $e) {
            Log::warning('Grafana access not restored: grafana:sync will retry', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Le mot de passe actuel ne marche plus, les sessions sont fermées, et l'utilisateur reçoit l'email
     * « mot de passe oublié » pour en choisir un nouveau.
     *
     * @throws AccountStatusException
     */
    public function forcePasswordReset(User $user, User $by): void
    {
        if ($user->is($by)) {
            throw new AccountStatusException('Use "My account" or "Forgot password" for your own password.');
        }

        $user->forceFill(['password' => Str::random(64), 'remember_token' => Str::random(60)])->save();
        $this->closeSessions($user);

        $status = Password::broker()->sendResetLink(['email' => $user->email]);
        AuditLog::record('user.password_reset_forced', $by, $user, [
            'email' => $user->email,
            ...($status === Password::RESET_LINK_SENT ? [] : ['email_status' => $status]),
        ]);
    }

    /**
     * Téléphone perdu (SCRUM-86) : la 2FA est retirée et les sessions fermées. Un administrateur devra la réactiver
     * à sa prochaine connexion.
     *
     * @throws AccountStatusException
     */
    public function resetTwoFactor(User $user, User $by): void
    {
        if ($user->is($by)) {
            throw new AccountStatusException('Ask another Super Admin to reset your own two-factor authentication.');
        }
        if ($user->two_factor_secret === null) {
            throw new AccountStatusException("{$user->name} has no two-factor authentication to reset.");
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => Str::random(60),
        ])->save();
        $this->closeSessions($user);
        AuditLog::record('user.2fa_reset', $by, $user, ['email' => $user->email]);
    }

    /** Sessions en base fermées tout de suite ; les autres drivers sont couverts par EnsureAccountIsActive */
    private function closeSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
