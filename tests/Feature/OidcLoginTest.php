<?php

namespace Tests\Feature;

use App\Http\Controllers\OidcController;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OidcClient;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Connexion SSO OpenID Connect (SCRUM-88) : fournisseur simulé (découverte, clés, jeton), jetons signés en RSA.
 */
class OidcLoginTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://sso.company.com/realms/acme';

    private const CLIENT_ID = 'monitoring-platform';

    private const SECRET = 'client-secret-value';

    /** @var array<string, array{private: string, jwk: array<string, string>}> clés RSA générées une fois */
    private static array $keys = [];

    /** @var array<string, mixed> claims du prochain jeton d'identité, remplacés par chaque test */
    private array $claims = [];

    private string $kid = 'k1';

    private ?string $signWith = null;

    private string $alg = 'RS256';

    /** @var list<array<string, mixed>> réponses successives du point JWKS */
    private array $jwksResponses = [];

    /** @var array<string, mixed> document de découverte servi par le fournisseur simulé */
    private array $discovery = [];

    /** Fournisseur injoignable */
    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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

        if ($this->alg === 'HS256') {
            return JWT::encode($payload, self::SECRET.str_repeat('x', 32), 'HS256', $this->kid);
        }

        return JWT::encode($payload, self::key($this->signWith ?? $this->kid)['private'], 'RS256', $this->kid);
    }

    /** Départ vers le fournisseur, puis retour avec un code ; le nonce de la session est repris dans le jeton */
    private function signIn(array $query = []): TestResponse
    {
        $this->get(route('oidc.redirect'))->assertRedirect();
        $pending = session('oidc');
        session(['oidc_test_nonce' => $pending['nonce']]);

        return $this->get(route('oidc.callback', array_merge(['state' => $pending['state'], 'code' => 'auth-code-123'], $query)));
    }

    private function alice(array $attributes = []): User
    {
        return User::factory()->withTeam()->create(['email' => 'alice@company.com', ...$attributes]);
    }

    // ─── Bouton et départ ────────────────────────────────────────────────

    public function test_the_button_appears_only_when_the_provider_is_configured(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sign in with Company SSO')->assertSee(route('oidc.redirect'));

        config(['oidc.client_secret' => '']);
        $this->get(route('login'))->assertOk()->assertDontSee('Sign in with Company SSO');
        $this->get(route('oidc.redirect'))->assertNotFound();
        $this->get(route('oidc.callback'))->assertNotFound();
    }

    public function test_the_redirect_uses_pkce_state_and_nonce(): void
    {
        $response = $this->get(route('oidc.redirect'));

        $pending = session('oidc');
        $this->assertSame(40, strlen($pending['state']));
        $this->assertSame(40, strlen($pending['nonce']));
        $this->assertSame(64, strlen($pending['verifier']));

        $url = $response->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/protocol/openid-connect/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'response_type' => 'code', 'client_id' => self::CLIENT_ID, 'redirect_uri' => route('oidc.callback'),
            'scope' => 'openid email profile', 'state' => $pending['state'], 'nonce' => $pending['nonce'],
            'code_challenge' => OidcClient::codeChallenge($pending['verifier']), 'code_challenge_method' => 'S256',
        ], $query);
        $this->assertStringNotContainsString($pending['verifier'], $url);
        // RFC 7636, annexe B
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', OidcClient::codeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    // ─── Connexion ───────────────────────────────────────────────────────

    public function test_a_linked_account_signs_in_by_its_subject(): void
    {
        $alice = $this->alice(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice']);
        // L'email a changé dans l'annuaire : la personne est retrouvée par iss + sub
        $this->claims = ['email' => 'alice.new@company.com'];

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($alice);
        $this->assertNotNull($alice->fresh()->last_login_at);
        $this->assertSame('alice@company.com', $alice->fresh()->email);
        $this->assertSame(1, AuditLog::where(['action' => 'user.login_sso', 'user_id' => $alice->id])->count());

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/token')) {
                return false;
            }
            $this->assertSame('Basic '.base64_encode(self::CLIENT_ID.':'.self::SECRET), $request->header('Authorization')[0]);
            $this->assertSame('authorization_code', $request['grant_type']);
            $this->assertSame('auth-code-123', $request['code']);
            $this->assertSame(route('oidc.callback'), $request['redirect_uri']);
            $this->assertSame(64, strlen($request['code_verifier']));
            $this->assertArrayNotHasKey('client_secret', $request->data());

            return true;
        });
    }

    public function test_the_first_sign_in_links_an_existing_account_by_verified_email(): void
    {
        $alice = $this->alice();

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($alice);
        $alice->refresh();
        $this->assertSame(self::ISSUER, $alice->oidc_issuer);
        $this->assertSame('subject-alice', $alice->oidc_subject);
        $this->assertSame(['provider' => 'sso.company.com'], AuditLog::where('action', 'user.sso_link')->sole()->details);
    }

    public function test_an_unverified_email_is_never_linked(): void
    {
        $alice = $this->alice();
        $this->claims = ['email_verified' => false];

        $this->signIn()->assertRedirect(route('login'))->assertSessionHasErrors(['sso' => OidcController::NO_ACCOUNT]);

        $this->assertGuest();
        $this->assertNull($alice->fresh()->oidc_subject);
    }

    public function test_an_account_linked_to_another_identity_is_not_taken_over_by_email(): void
    {
        $alice = $this->alice(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice']);
        $this->claims = ['sub' => 'subject-mallory'];

        $this->signIn()->assertSessionHasErrors(['sso' => OidcController::NO_ACCOUNT]);

        $this->assertGuest();
        $this->assertSame('subject-alice', $alice->fresh()->oidc_subject);
    }

    public function test_an_unknown_person_gets_no_account(): void
    {
        $this->signIn()->assertSessionHasErrors(['sso' => OidcController::NO_ACCOUNT]);

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_deactivated_account_is_refused_even_with_a_valid_token(): void
    {
        $linked = $this->alice(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice', 'deactivated_at' => now()]);

        $this->signIn()->assertSessionHasErrors(['sso' => OidcController::DEACTIVATED]);
        $this->assertGuest();

        // Pas encore lié : refusé aussi, et pas lié
        $linked->delete();
        $bob = User::factory()->create(['email' => 'alice@company.com', 'deactivated_at' => now()]);
        $this->signIn()->assertSessionHasErrors(['sso' => OidcController::DEACTIVATED]);
        $this->assertNull($bob->fresh()->oidc_subject);
        $this->assertSame(0, AuditLog::where('action', 'user.login_sso')->count());
    }

    public function test_platform_two_factor_is_still_asked(): void
    {
        $alice = $this->alice(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice']);
        $alice->forceFill([
            'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->signIn()->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame($alice->id, session('login.id'));
        $this->post(route('two-factor.login'), ['recovery_code' => 'recovery-one'])->assertRedirect();
        $this->assertAuthenticatedAs($alice);
    }

    // ─── Refus ───────────────────────────────────────────────────────────

    public function test_state_is_checked_and_used_once(): void
    {
        $this->alice(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice']);

        $this->get(route('oidc.redirect'));
        $state = session('oidc.state');
        session(['oidc_test_nonce' => session('oidc.nonce')]);

        $this->get(route('oidc.callback', ['state' => 'forged', 'code' => 'x']))->assertSessionHasErrors(['sso' => OidcController::FAILED]);
        // Le state consommé par la tentative précédente ne sert plus
        $this->get(route('oidc.callback', ['state' => $state, 'code' => 'x']))->assertSessionHasErrors(['sso' => OidcController::FAILED]);
        $this->get(route('oidc.callback', ['code' => 'x']))->assertSessionHasErrors('sso');

        $this->assertGuest();
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/token'));
    }

    public function test_a_slow_login_is_refused(): void
    {
        $this->alice(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice']);
        $this->get(route('oidc.redirect'));
        $pending = session('oidc');
        session(['oidc_test_nonce' => $pending['nonce']]);

        $this->travel(11)->minutes();
        $this->get(route('oidc.callback', ['state' => $pending['state'], 'code' => 'x']))->assertSessionHasErrors('sso');
        $this->assertGuest();
    }

    public function test_a_cancelled_login_does_not_call_the_token_endpoint(): void
    {
        Log::spy();

        $this->signIn(['error' => 'access_denied', 'code' => null])->assertSessionHasErrors(['sso' => OidcController::FAILED]);

        $this->assertGuest();
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/token'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($context['reason'], 'access_denied'));
    }

    /** @return array<string, array{0: array<string, mixed>, 1?: array<string, string>}> */
    public static function badTokens(): array
    {
        return [
            'other issuer' => [['iss' => 'https://evil.example.com/realms/acme']],
            'other audience' => [['aud' => 'another-app']],
            'several audiences without azp' => [['aud' => [self::CLIENT_ID, 'another-app']]],
            'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200]],
            'issued in the future' => [['iat' => time() + 3600]],
            'other nonce' => [['nonce' => 'replayed-nonce']],
            'no subject' => [['sub' => '']],
            'signed by an unknown key' => [[], ['signWith' => 'attacker']],
            'HS256 with a guessable key' => [[], ['alg' => 'HS256']],
        ];
    }

    #[DataProvider('badTokens')]
    public function test_an_invalid_id_token_is_refused(array $claims, array $options = []): void
    {
        $this->alice(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice']);
        $this->claims = $claims;
        $this->signWith = $options['signWith'] ?? null;
        $this->alg = $options['alg'] ?? 'RS256';
        Log::spy();

        $this->signIn()->assertSessionHasErrors(['sso' => OidcController::FAILED]);

        $this->assertGuest();
        // Le détail va dans les logs, jamais le code ni le jeton
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'SSO sign-in failed'
            && ! str_contains(json_encode($context), 'auth-code-123') && ! str_contains(json_encode($context), 'eyJ'));
    }

    public function test_several_audiences_are_accepted_with_azp(): void
    {
        $alice = $this->alice(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice']);
        $this->claims = ['aud' => [self::CLIENT_ID, 'account'], 'azp' => self::CLIENT_ID];

        $this->signIn()->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($alice);
    }

    public function test_a_new_signing_key_is_fetched_once(): void
    {
        $alice = $this->alice(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice']);
        // Clés en cache : k1 seulement ; le fournisseur signe maintenant avec k2
        app(OidcClient::class)->discovery();
        $this->jwksResponses = [$this->jwks(['k1']), $this->jwks(['k1', 'k2'])];
        $this->kid = 'k2';

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($alice);
        Http::assertSentCount(4);   // découverte, clés (k1), clés relues (k2), jeton
    }

    public function test_a_discovery_document_from_another_issuer_is_refused(): void
    {
        $this->discovery = ['issuer' => 'https://evil.example.com', 'authorization_endpoint' => 'https://evil.example.com/auth', 'token_endpoint' => 'x', 'jwks_uri' => 'y'];

        $this->get(route('oidc.redirect'))->assertRedirect(route('login'))->assertSessionHasErrors(['sso' => OidcController::FAILED]);
    }

    public function test_an_unreachable_provider_shows_the_generic_message(): void
    {
        $this->down = true;

        $this->get(route('oidc.redirect'))->assertRedirect(route('login'))->assertSessionHasErrors(['sso' => OidcController::FAILED]);
        $this->followingRedirects()->get(route('oidc.redirect'))->assertSee(OidcController::FAILED);
    }

    public function test_the_internal_url_is_used_for_discovery_only(): void
    {
        config(['oidc.internal_url' => 'http://keycloak:8080/realms/acme']);
        $this->discovery['token_endpoint'] = 'http://keycloak:8080/realms/acme/protocol/openid-connect/token';
        $this->discovery['jwks_uri'] = 'http://keycloak:8080/realms/acme/protocol/openid-connect/certs';
        $alice = $this->alice(['oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice']);

        $url = $this->get(route('oidc.redirect'))->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/protocol/openid-connect/auth?', $url);   // le navigateur : adresse publique

        $this->signIn()->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($alice);
        // Le serveur : découverte, clés et jeton par l'adresse interne ; l'émetteur du jeton reste l'adresse publique
        foreach (['.well-known/openid-configuration', 'protocol/openid-connect/certs', 'protocol/openid-connect/token'] as $path) {
            Http::assertSent(fn (Request $request) => $request->url() === "http://keycloak:8080/realms/acme/{$path}");
        }
    }
}
