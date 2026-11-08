<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Comment la session a été ouverte (SCRUM-90), lu par EnforceSsoSession : auth.via (sso, break_glass, password),
 * auth.at, auth.mfa (MFA attestée par l'annuaire) et le jeton d'identité pour la déconnexion chez le fournisseur.
 *
 * Le SSO et le compte de secours préparent « auth.pending » avant la connexion, qui peut passer par le défi 2FA de
 * Fortify : repris seulement pour le même compte et dans les 10 minutes, sinon la connexion compte comme un mot de passe.
 */
class RecordSignInMethod
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || ! app()->bound('session') || ! session()->isStarted()) {
            return;
        }

        $pending = session()->pull('auth.pending');
        $valid = is_array($pending)
            && ($pending['user_id'] ?? null) === $event->user->getKey()
            && now()->getTimestamp() - (int) ($pending['at'] ?? 0) <= (int) config('oidc.max_login_seconds');

        session()->put([
            'auth.via' => $valid ? $pending['via'] : 'password',
            'auth.at' => now()->getTimestamp(),
            'auth.mfa' => $valid && ($pending['mfa'] ?? false) === true,
        ]);
        $valid && isset($pending['id_token']) ? session()->put('auth.id_token', $pending['id_token']) : session()->forget('auth.id_token');
    }
}
