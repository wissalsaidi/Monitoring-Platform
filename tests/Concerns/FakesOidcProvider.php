<?php

namespace Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Fournisseur OpenID Connect simulé (SCRUM-88, 89) : découverte, clés (JWKS) et jeton d'identité signé en RSA.
 * Les claims du prochain jeton sont dans $this->claims.
 */
trait FakesOidcProvider
{
    private const ISSUER = 'https://sso.company.com/realms/acme';

    private const CLIENT_ID = 'monitoring-platform';

    private const SECRET = 'client-secret-value';

    /** @var array<string, array{private: string, jwk: array<string, string>}> clés RSA générées une fois */
    private static array $keys = [];

    /** @var array<string, mixed> claims du prochain jeton d'identité, remplacés par chaque test */
    private array $claims = [];

    /** @var list<string> claims absents du prochain jeton (ex. email_verified, que Entra ID n'envoie pas) */
    private array $withoutClaims = [];

    private string $kid = 'k1';

    private ?string $signWith = null;

    private string $alg = 'RS256';

    /** @var list<array<string, mixed>> réponses successives du point JWKS */
    private array $jwksResponses = [];

    /** @var array<string, mixed> document de découverte servi par le fournisseur simulé */
    private array $discovery = [];

    /** Fournisseur injoignable */
    private bool $down = false;

    /** Fournisseur configuré et simulé ; à appeler dans setUp() */
    private function setUpOidcProvider(): void
    {
        config([
            'oidc.issuer' => self::ISSUER, 'oidc.internal_url' => '', 'oidc.client_id' => self::CLIENT_ID,
            'oidc.client_secret' => self::SECRET, 'oidc.button_label' => 'Sign in with Company SSO',
        ]);
        $this->jwksResponses = [$this->jwks(['k1'])];
        $this->discovery = [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => self::ISSUER.'/protocol/openid-connect/token',
            'jwks_uri' => self::ISSUER.'/protocol/openid-connect/certs',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
        ];
        $this->fakeProvider();
    }

    // ─── Fournisseur simulé ──────────────────────────────────────────────

    private static function key(string $kid): array
    {
        if (! isset(self::$keys[$kid])) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, $private);
            $details = openssl_pkey_get_details($resource)['rsa'];
            $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
            self::$keys[$kid] = ['private' => $private, 'jwk' => [
                'kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($details['n']), 'e' => $b64($details['e']),
            ]];
        }

        return self::$keys[$kid];
    }

    /** JWKS avec une clé de chiffrement en plus, à ignorer */
    private function jwks(array $kids): array
    {
        $keys = array_map(fn (string $kid) => self::key($kid)['jwk'], $kids);
        $keys[] = ['kty' => 'RSA', 'kid' => 'enc-1', 'use' => 'enc', 'alg' => 'RSA-OAEP', 'n' => self::key('k1')['jwk']['n'], 'e' => 'AQAB'];

        return ['keys' => $keys];
    }

    /** Un seul fake, piloté par les propriétés du test (un second Http::fake ne remplacerait pas le premier) */
    private function fakeProvider(): void
    {
        Http::fake(function (Request $request) {
            if ($this->down) {
                throw new ConnectionException('Connection refused');
            }

            return match (true) {
                str_ends_with($request->url(), '/.well-known/openid-configuration') => Http::response($this->discovery),
                str_ends_with($request->url(), '/certs') => Http::response(count($this->jwksResponses) > 1 ? array_shift($this->jwksResponses) : $this->jwksResponses[0]),
                str_ends_with($request->url(), '/token') => Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $this->idToken()]),
                default => Http::response('', 404),
            };
        });
    }

    private function idToken(): string
    {
        $payload = array_merge([
            'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'sub' => 'subject-alice', 'iat' => time(), 'exp' => time() + 300,
            'nonce' => session('oidc_test_nonce'), 'email' => 'alice@company.com', 'email_verified' => true, 'name' => 'Alice',
        ], $this->claims);
        $payload = array_diff_key($payload, array_flip($this->withoutClaims));

        if ($this->alg === 'HS256') {
            return JWT::encode($payload, self::SECRET.str_repeat('x', 32), 'HS256', $this->kid);
        }

        return JWT::encode($payload, self::key($this->signWith ?? $this->kid)['private'], 'RS256', $this->kid);
    }

    /** Confirmation d'identité d'une personne connectée (SCRUM-89) : même aller-retour, avec reconnexion forcée */
    private function reauth(array $query = []): TestResponse
    {
        $this->get(route('oidc.reauth', $query))->assertRedirect();
        $pending = session('oidc');
        session(['oidc_test_nonce' => $pending['nonce']]);

        return $this->get(route('oidc.callback', ['state' => $pending['state'], 'code' => 'auth-code-456']));
    }

    /** Départ vers le fournisseur, puis retour avec un code ; le nonce de la session est repris dans le jeton */
    private function signIn(array $query = []): TestResponse
    {
        $this->get(route('oidc.redirect'))->assertRedirect();
        $pending = session('oidc');
        session(['oidc_test_nonce' => $pending['nonce']]);

        return $this->get(route('oidc.callback', array_merge(['state' => $pending['state'], 'code' => 'auth-code-123'], $query)));
    }
}
