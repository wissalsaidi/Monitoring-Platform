<?php

namespace App\Http\Controllers;

use App\Services\GrafanaAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Auth proxy de Grafana (SCRUM-48). nginx appelle cette route (auth_request) avant chaque requête vers /grafana/ :
 * - 200 + en-têtes X-WEBAUTH-* : nginx les transmet à Grafana, qui connecte ce compte (sans mot de passe) ;
 * - 401 : pas connecté (ou email non vérifié) → nginx redirige vers /login ;
 * - 403 : pas encore de compte Grafana (il est créé au premier clic sur « Open Grafana dashboard »).
 *
 * Grafana n'accepte ces en-têtes que de nginx (GF_AUTH_PROXY_WHITELIST, réseau grafana-proxy) et nginx remplace
 * toujours ceux envoyés par le navigateur : impossible de se faire passer pour un autre compte.
 */
class GrafanaAuthController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasVerifiedEmail()) {
            return response('', 401);
        }

        if ($user->grafana_user_id === null) {
            return response('', 403);
        }

        return response('', 200, [
            'X-WEBAUTH-USER' => GrafanaAccessService::login($user),
            // En-têtes HTTP : pas de retour à la ligne possible ; nom et email tels qu'enregistrés
            'X-WEBAUTH-NAME' => str_replace(["\r", "\n"], ' ', $user->name),
            'X-WEBAUTH-EMAIL' => $user->email,
            'Cache-Control' => 'no-store',
        ]);
    }
}
