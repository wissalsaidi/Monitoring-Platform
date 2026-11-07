<?php

namespace App\Services;

use App\Exceptions\OidcException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client OpenID Connect (SCRUM-88) : flux « authorization code » avec PKCE S256.
 *
 * Découverte (/.well-known/openid-configuration) et clés (JWKS) en cache. Le jeton d'identité est vérifié ici :
 * signature par une clé du fournisseur, iss, aud (et azp), exp, iat, nonce. Une clé inconnue (rotation chez le
 * fournisseur) fait relire les clés une fois.
 */
class OidcClient
{
    /** Algorithmes acceptés pour la signature du jeton d'identité (jamais « none » ni HS*, qui utiliserait le secret) */
    public const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384'];

    public static function enabled(): bool
    {
        return config('oidc.issuer') !== '' && config('oidc.client_id') !== '' && config('oidc.client_secret') !== '';
    }

    public function authorizationUrl(string $state, string $nonce, string $codeVerifier): string
    {
        return $this->discovery()['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('oidc.client_id'),
            'redirect_uri' => self::redirectUri(),
            'scope' => config('oidc.scopes'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Échange du code contre les jetons, côté serveur, avec le secret du client et le code_verifier (PKCE).
     *
     * @return array<string, mixed> claims du jeton d'identité, vérifiés
     *
     * @throws OidcException
     */
    public function authenticate(string $code, string $codeVerifier, string $nonce): array
    {
        $discovery = $this->discovery();
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            'code_verifier' => $codeVerifier,
        ];

        // client_secret_basic par défaut (RFC 6749 : identifiants encodés), client_secret_post si c'est le seul proposé
        $methods = $discovery['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        $request = Http::asForm()->acceptJson()->timeout(config('oidc.timeout'));
        if (in_array('client_secret_basic', $methods, true)) {
            $request = $request->withBasicAuth(urlencode((string) config('oidc.client_id')), urlencode((string) config('oidc.client_secret')));
        } else {
            $form += ['client_id' => config('oidc.client_id'), 'client_secret' => config('oidc.client_secret')];
        }

        try {
            $response = $request->post($discovery['token_endpoint'], $form);
        } catch (ConnectionException) {
            throw new OidcException('Token endpoint unreachable.');
        }
        if (! $response->successful() || ! is_string($response->json('id_token'))) {
            // Seul le code d'erreur OAuth est gardé : jamais le corps (il pourrait contenir un jeton)
            throw new OidcException("Token request refused (HTTP {$response->status()}, ".((string) ($response->json('error') ?? 'no id_token')).').');
        }

        return $this->verifyIdToken($response->json('id_token'), $nonce);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    public function verifyIdToken(string $idToken, string $nonce): array
    {
        $header = json_decode((string) JWT::urlsafeB64Decode(explode('.', $idToken)[0] ?? ''), true);
        if (! is_array($header) || ! in_array($header['alg'] ?? null, self::ALGORITHMS, true)) {
            throw new OidcException('ID token: unsupported or missing algorithm.');
        }

        $keys = $this->keys();
        // Clé inconnue : le fournisseur a peut-être changé de clés, on les relit une fois
        if (isset($header['kid']) && ! isset($keys[$header['kid']])) {
            $keys = $this->keys(refresh: true);
        }

        JWT::$leeway = (int) config('oidc.leeway');
        try {
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (Throwable $e) {
            throw new OidcException('ID token rejected: '.class_basename($e).' ('.$e->getMessage().').');
        }

        $audiences = (array) ($claims['aud'] ?? []);
        $checks = [
            'iss' => ($claims['iss'] ?? null) === config('oidc.issuer'),
            'aud' => in_array(config('oidc.client_id'), $audiences, true),
            // Plusieurs audiences : le jeton doit avoir été émis pour nous (azp)
            'azp' => count($audiences) <= 1 || ($claims['azp'] ?? null) === config('oidc.client_id'),
            'exp' => isset($claims['exp']),
            'iat' => isset($claims['iat']),
            'nonce' => is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce']),
            'sub' => is_string($claims['sub'] ?? null) && $claims['sub'] !== '',
        ];
        if ($failed = array_keys(array_filter($checks, fn (bool $ok) => ! $ok))) {
            throw new OidcException('ID token rejected: invalid '.implode(', ', $failed).'.');
        }

        return $claims;
    }

    /**
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string}&array<string, mixed>
     *
     * @throws OidcException
     */
    public function discovery(): array
    {
        $base = rtrim(config('oidc.internal_url') ?: config('oidc.issuer'), '/');

        $document = Cache::remember('oidc.discovery.'.md5($base), config('oidc.cache_seconds'), function () use ($base) {
            $json = $this->getJson($base.'/.well-known/openid-configuration');

            return is_array($json) ? $json : null;
        });

        if (! is_array($document) || ($document['issuer'] ?? null) !== config('oidc.issuer')) {
            Cache::forget('oidc.discovery.'.md5($base));
            throw new OidcException('Discovery document missing or issued for another issuer.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $endpoint) {
            if (! is_string($document[$endpoint] ?? null)) {
                throw new OidcException("Discovery document without {$endpoint}.");
            }
        }

        return $document;
    }

    /** URL de retour, fixe : la même pour toute l'installation, déclarée chez le fournisseur */
    public static function redirectUri(): string
    {
        return route('oidc.callback');
    }

    public static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * Clés de signature du fournisseur (les clés de chiffrement « use: enc » sont ignorées)
     *
     * @return array<string, Key>
     *
     * @throws OidcException
     */
    private function keys(bool $refresh = false): array
    {
        $uri = $this->discovery()['jwks_uri'];
        $cacheKey = 'oidc.jwks.'.md5($uri);
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $jwks = Cache::remember($cacheKey, config('oidc.cache_seconds'), function () use ($uri) {
            $json = $this->getJson($uri);

            return is_array($json['keys'] ?? null) ? $json : null;
        });
        if (! is_array($jwks)) {
            Cache::forget($cacheKey);
            throw new OidcException('JWKS missing.');
        }

        $signing = array_values(array_filter($jwks['keys'], fn ($key) => is_array($key)
            && ($key['use'] ?? 'sig') === 'sig'
            && in_array($key['alg'] ?? 'RS256', self::ALGORITHMS, true)));

        try {
            return JWK::parseKeySet(['keys' => $signing], 'RS256');
        } catch (Throwable $e) {
            throw new OidcException('JWKS unreadable: '.$e->getMessage());
        }
    }

    /** @throws OidcException */
    private function getJson(string $url): mixed
    {
        try {
            $response = Http::acceptJson()->timeout(config('oidc.timeout'))->get($url);
        } catch (ConnectionException) {
            throw new OidcException('Identity provider unreachable.');
        }

        return $response->successful() ? $response->json() : null;
    }
}
