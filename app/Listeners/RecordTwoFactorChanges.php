<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Audit de la 2FA (SCRUM-86) : activation (une fois le premier code confirmé), désactivation et nouveaux codes de
 * secours. L'utilisateur agit sur son propre compte : il est l'auteur et l'objet de l'entrée.
 */
class RecordTwoFactorChanges
{
    public function handleConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record('user.2fa_enable', $event->user);
    }

    public function handleDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record('user.2fa_disable', $event->user);
    }

    public function handleRecoveryCodes(RecoveryCodesGenerated $event): void
    {
        // Codes générés aussi à l'activation, avant la confirmation : seuls les codes régénérés ensuite sont tracés
        if ($event->user instanceof User && $event->user->two_factor_confirmed_at !== null) {
            $this->record('user.2fa_recovery_codes', $event->user);
        }
    }

    private function record(string $action, mixed $user): void
    {
        if ($user instanceof User) {
            AuditLog::record($action, $user, $user, ['email' => $user->email]);
        }
    }
}
