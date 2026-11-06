<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte désactivé pendant qu'il était connecté (SCRUM-84) : déconnecté à la requête suivante, quel que soit
 * le driver de session ou le cookie « remember me ».
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Auth proxy de Grafana : il répond lui-même 401 à un invité, sans redirection
            if (! $request->routeIs('grafana.auth')) {
                return redirect()->route('login')->withErrors(['email' => 'This account has been deactivated. Contact your administrator.']);
            }
        }

        return $next($request);
    }
}
