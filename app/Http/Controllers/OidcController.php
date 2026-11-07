<?php

namespace App\Http\Controllers;

use App\Exceptions\OidcException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Connexion SSO OpenID Connect (SCRUM-88).
 *
 * redirect : state, nonce et code_verifier (PKCE) aléatoires, gardés dans la session, puis départ vers le fournisseur.
 * callback : state contrôlé et consommé (usage unique, 10 min), code échangé côté serveur, jeton d'identité vérifié,
 * puis la personne est retrouvée par iss + sub. Première connexion d'un compte existant : liaison si l'annuaire
 * atteste l'email (email_verified). Création de compte et invitations : SCRUM-89.
 *
 * Toute erreur ramène à la page de connexion avec un message générique ; le détail va dans les logs, sans jeton ni code.
 */
class OidcController extends Controller
{
    public const FAILED = 'Single sign-on failed. Try again, or contact your administrator.';

    public const NO_ACCOUNT = 'No account on this platform matches your company account. Ask your administrator for an invitation.';

    public const DEACTIVATED = 'This account has been deactivated. Contact your administrator.';

    public function redirect(Request $request, OidcClient $oidc): RedirectResponse
    {
        abort_unless(OidcClient::enabled(), 404);

        $pending = [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(64),          // PKCE : 43 à 128 caractères non réservés
            'started_at' => now()->getTimestamp(),
        ];

        try {
            $url = $oidc->authorizationUrl($pending['state'], $pending['nonce'], $pending['verifier']);
        } catch (OidcException $e) {
            return $this->fail($e->getMessage());
        }

        $request->session()->put('oidc', $pending);

        return redirect()->away($url);
    }

    public function callback(Request $request, OidcClient $oidc): RedirectResponse
    {
        abort_unless(OidcClient::enabled(), 404);

        // Usage unique : retiré de la session quoi qu'il arrive
        $pending = $request->session()->pull('oidc');
        $state = (string) $request->query('state', '');

        if (! is_array($pending) || $state === '' || ! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            return $this->fail('Invalid or missing state (CSRF, replay or expired session).');
        }
        if (now()->getTimestamp() - (int) $pending['started_at'] > config('oidc.max_login_seconds')) {
            return $this->fail('Login took too long.');
        }
        // Annulation ou refus chez le fournisseur : seul le code d'erreur OAuth est gardé
        if ($request->filled('error')) {
            return $this->fail('Provider returned an error: '.Str::limit((string) $request->query('error'), 50).'.');
        }
        if (! is_string($request->query('code')) || $request->query('code') === '') {
            return $this->fail('No authorization code.');
        }

        try {
            $claims = $oidc->authenticate($request->query('code'), $pending['verifier'], $pending['nonce']);
        } catch (OidcException $e) {
            return $this->fail($e->getMessage());
        }

        $user = $this->findOrLink($claims);
        if ($user === null) {
            return $this->fail('No linked account and no verified matching email.', self::NO_ACCOUNT);
        }
        if (! $user->isActive()) {
            return $this->fail("Deactivated account (user #{$user->id}).", self::DEACTIVATED);
        }

        AuditLog::record('user.login_sso', $user, $user);

        // 2FA de la plateforme activée : même défi qu'après un mot de passe (MFA de l'annuaire : SCRUM-90)
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Compte lié à cette identité (iss + sub) ; sinon, première connexion d'un compte existant dont l'email est
     * attesté par l'annuaire et qui n'est pas déjà lié à une autre identité.
     *
     * @param  array<string, mixed>  $claims
     */
    private function findOrLink(array $claims): ?User
    {
        $issuer = (string) $claims['iss'];
        $subject = (string) $claims['sub'];

        $linked = User::where('oidc_issuer', $issuer)->where('oidc_subject', $subject)->first();
        if ($linked !== null) {
            return $linked;
        }

        $email = is_string($claims['email'] ?? null) ? Str::lower($claims['email']) : null;
        if ($email === null || ($claims['email_verified'] ?? false) !== true) {
            return null;
        }

        $user = User::where('email', $email)->first();
        // Déjà lié à une autre identité : jamais repris par l'email
        if ($user === null || $user->oidc_subject !== null) {
            return null;
        }
        // Compte désactivé : refusé ensuite, sans liaison
        if (! $user->isActive()) {
            return $user;
        }

        $user->forceFill(['oidc_issuer' => $issuer, 'oidc_subject' => $subject])->save();
        AuditLog::record('user.sso_link', $user, $user, ['provider' => parse_url($issuer, PHP_URL_HOST)]);

        return $user;
    }

    /** Message générique à l'écran, détail dans les logs */
    private function fail(string $reason, string $message = self::FAILED): RedirectResponse
    {
        Log::warning('SSO sign-in failed', ['reason' => $reason]);

        return redirect()->route('login')->withErrors(['sso' => $message]);
    }
}
