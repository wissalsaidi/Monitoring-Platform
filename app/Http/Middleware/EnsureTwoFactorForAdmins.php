<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA obligatoire pour les administrateurs (SCRUM-86) : un Super Admin ou un Admin de team sans 2FA confirmée est
 * renvoyé sur la page d'activation. « My account » et la page 2FA restent accessibles.
 */
class EnsureTwoFactorForAdmins
{
    /** Routes accessibles avant l'activation */
    private const ALLOWED = ['account.edit', 'account.destroy', 'account.two-factor', 'account.two-factor.enable'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $request->routeIs(...self::ALLOWED) && $user->mustSetUpTwoFactor()) {
            return redirect()->route('account.two-factor')
                ->with('warning', 'Your role requires two-factor authentication. Set it up to continue.');
        }

        return $next($request);
    }
}
