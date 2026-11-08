<?php

namespace App\Http\Responses;

use App\Services\OidcClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Après « Log out » (SCRUM-90) : session ouverte par le SSO → déconnexion aussi chez le fournisseur
 * (end_session_endpoint, retour sur la page de connexion), s'il la propose. Sinon, réponse habituelle de Fortify.
 */
class SsoLogoutResponse implements LogoutResponse
{
    public function __construct(private OidcClient $oidc) {}

    /** @param  Request  $request */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $sso = $request->attributes->get('sso_logout');
        if (is_array($sso) && $sso['via'] === 'sso' && OidcClient::enabled()) {
            $url = $this->oidc->endSessionUrl($sso['id_token'], route('login'));
            if ($url !== null) {
                return redirect()->away($url);
            }
        }

        return redirect(Fortify::redirects('logout', '/'));
    }
}
