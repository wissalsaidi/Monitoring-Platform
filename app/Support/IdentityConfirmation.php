<?php

namespace App\Support;

/**
 * Identité confirmée récemment dans cette session (auth.password_confirmed_at, même clé que « Confirm your password ») :
 * mot de passe saisi, ou connexion / confirmation SSO pour un compte sans mot de passe (SCRUM-89).
 */
final class IdentityConfirmation
{
    public static function isRecent(): bool
    {
        return time() - (int) session('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout');
    }
}
