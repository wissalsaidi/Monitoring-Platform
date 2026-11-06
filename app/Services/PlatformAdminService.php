<?php

namespace App\Services;

use App\Enums\PlatformRole;
use App\Exceptions\PlatformAdminException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin de la plateforme (SCRUM-81) : seul endroit qui donne ou retire ce rôle, toujours tracé dans l'audit.
 * Garde-fou : la plateforme garde au moins un Super Admin (verrou sur les Super Admin pendant le retrait).
 */
class PlatformAdminService
{
    /** @param  ?User  $by  null quand l'action vient de la console */
    public function grant(User $user, ?User $by = null): bool
    {
        if ($user->isSuperAdmin()) {
            return false;
        }

        $user->forceFill(['platform_role' => PlatformRole::SuperAdmin])->save();
        AuditLog::record('platform.admin_grant', $by, $user, ['email' => $user->email, ...$by ? [] : ['via' => 'console']]);

        return true;
    }

    /** @throws PlatformAdminException si c'est le dernier Super Admin, ou si $user n'en est pas un */
    public function revoke(User $user, ?User $by = null): void
    {
        DB::transaction(function () use ($user, $by) {
            if (! $user->fresh()?->isSuperAdmin()) {
                throw new PlatformAdminException("{$user->email} is not a Super Admin.");
            }
            // Seuls les Super Admin actifs comptent (SCRUM-84) : un compte désactivé ne peut plus administrer
            $active = User::superAdmins()->active()->lockForUpdate()->pluck('id');
            if ($user->isActive() && $active->count() === 1) {
                throw new PlatformAdminException('The platform must keep at least one Super Admin. Promote someone else first.');
            }

            $user->forceFill(['platform_role' => null])->save();
            AuditLog::record('platform.admin_revoke', $by, $user, ['email' => $user->email, ...$by ? [] : ['via' => 'console']]);
        });
    }

    public function isLastSuperAdmin(User $user): bool
    {
        return $user->isSuperAdmin() && $user->isActive() && User::superAdmins()->active()->count() === 1;
    }
}
