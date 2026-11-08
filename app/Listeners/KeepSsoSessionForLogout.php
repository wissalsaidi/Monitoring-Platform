<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Logout;

/**
 * Déconnexion (SCRUM-90) : la session est vidée juste après cet événement. On garde pour la réponse (SsoLogoutResponse)
 * le mode de connexion et le jeton d'identité, pour fermer aussi la session chez le fournisseur.
 */
class KeepSsoSessionForLogout
{
    public function handle(Logout $event): void
    {
        if (! app()->bound('session') || ! session()->isStarted()) {
            return;
        }

        request()->attributes->set('sso_logout', [
            'via' => session('auth.via'),
            'id_token' => session('auth.id_token'),
        ]);
    }
}
