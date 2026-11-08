<?php

namespace App\Http\Middleware;

use App\Services\OidcClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sessions et SSO (SCRUM-90) :
 * - une session ouverte par le SSO ou le compte de secours dure SSO_SESSION_LIFETIME (8 h), puis nouveau passage par
 *   l'annuaire : un compte coupé dans l'annuaire perd l'accès au plus tard à ce moment-là ;
 * - SSO obligatoire : une session ouverte autrement (mot de passe d'avant, cookie « remember me ») est fermée, sauf
 *   celle du compte de secours, s'il l'est toujours.
 */
class EnforceSsoSession
{
    public const EXPIRED = 'Your session has expired. Sign in again with single sign-on.';

    public const SSO_REQUIRED = 'Single sign-on is now required. Sign in with your company account.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $via = $request->session()->get('auth.via');
        $timed = in_array($via, ['sso', 'break_glass'], true);
        $expired = $timed && now()->getTimestamp() - (int) $request->session()->get('auth.at', 0) > (int) config('oidc.session_lifetime') * 60;
        $refused = OidcClient::required() && ! ($via === 'sso' || ($via === 'break_glass' && $user->isBreakGlass()));

        if (! $expired && ! $refused) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Auth proxy de Grafana : il répond lui-même 401 à un invité, sans redirection
        if ($request->routeIs('grafana.auth')) {
            return $next($request);
        }

        return redirect()->route('login')->withErrors(['sso' => $expired ? self::EXPIRED : self::SSO_REQUIRED]);
    }
}
