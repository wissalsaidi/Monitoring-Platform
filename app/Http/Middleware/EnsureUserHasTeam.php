<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un utilisateur sans team n'a rien à voir (SCRUM-82) : il est envoyé sur « Waiting for access »
 * au lieu d'un dashboard vide. Mon compte et l'acceptation d'une invitation restent accessibles.
 */
class EnsureUserHasTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->teams()->exists()) {
            return redirect()->route('access.waiting');
        }

        return $next($request);
    }
}
