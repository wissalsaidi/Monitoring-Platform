<?php

namespace App\Http\Middleware;

use App\Services\OidcClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité de toutes les pages (OWASP A05, SCRUM-61).
 *
 * Aucun script inline n'est autorisé : le JavaScript vient uniquement des fichiers Vite
 * (attributs data-auto-submit et data-confirm à la place de onchange / onsubmit).
 * Les styles inline restent permis : attributs style="" des vues et styles posés par Chart.js.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // La page d'erreur de Laravel en mode debug utilise des scripts inline : pas de CSP sur elle
        if (! (config('app.debug') && $response->getStatusCode() >= 500)) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        // HSTS seulement en HTTPS, et jamais pour localhost : le navigateur l'appliquerait à tous ses ports (Zabbix, Grafana…)
        $maxAge = (int) config('security.hsts_max_age');
        if ($request->isSecure() && $maxAge > 0 && ! in_array($request->getHost(), ['localhost', '127.0.0.1'], true)) {
            $headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        // npm run dev : les fichiers viennent du serveur Vite (http://localhost:5173) et le rechargement passe par un websocket
        $vite = Vite::isRunningHot() ? $this->viteDevServer() : [];

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", ...$vite],
            'style-src' => ["'self'", "'unsafe-inline'", ...$vite],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'", ...$vite],
            'connect-src' => ["'self'", ...$vite, ...array_map(fn ($url) => preg_replace('#^http#', 'ws', $url), $vite)],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            // Déconnexion SSO (SCRUM-90) : le formulaire « Log out » est redirigé vers le fournisseur, et form-action
            // s'applique aussi aux redirections qui suivent l'envoi d'un formulaire
            'form-action' => ["'self'", ...$this->identityProvider()],
            'frame-ancestors' => ["'none'"],
        ];

        return collect($directives)->map(fn ($sources, $name) => $name.' '.implode(' ', $sources))->implode('; ');
    }

    /** @return list<string> origine du fournisseur SSO configuré (ex. https://login.microsoftonline.com) */
    private function identityProvider(): array
    {
        if (! OidcClient::enabled()) {
            return [];
        }
        $parts = parse_url((string) config('oidc.issuer'));
        if (! isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        return [$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')];
    }

    /** @return list<string> */
    private function viteDevServer(): array
    {
        $url = rtrim((string) @file_get_contents(Vite::hotFile()));

        return $url === '' ? [] : [$url];
    }
}
