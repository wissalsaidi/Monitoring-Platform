<?php

namespace App\Http\Middleware;

use App\Services\OidcClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SSO obligatoire (SCRUM-90), sur les routes de Fortify : connexion par mot de passe refusée côté serveur (même par une
 * requête forgée), inscription et réinitialisation de mot de passe désactivées. Le défi 2FA reste ouvert (SSO sans
 * MFA de l'annuaire, compte de secours).
 */
class DisablePasswordAuthWhenSsoRequired
{
    private const DISABLED = ['register', 'register.store', 'password.request', 'password.email', 'password.reset', 'password.update'];

    public const PASSWORD_DISABLED = 'Password login is disabled. Sign in with single sign-on.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! OidcClient::required()) {
            return $next($request);
        }

        if ($request->routeIs('login.store')) {
            return redirect()->route('login')->withErrors(['sso' => self::PASSWORD_DISABLED]);
        }
        abort_if($request->routeIs(...self::DISABLED), 404);

        return $next($request);
    }
}
