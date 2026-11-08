<?php

namespace App\Http\Controllers;

use App\Exceptions\OidcException;
use App\Exceptions\SsoAccessException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OidcClient;
use App\Services\SsoAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Connexion SSO OpenID Connect (SCRUM-88, SCRUM-89).
 *
 * redirect : state, nonce et code_verifier (PKCE) aléatoires, gardés dans la session, puis départ vers le fournisseur.
 * callback : state contrôlé et consommé (usage unique, 10 min), code échangé côté serveur, jeton d'identité vérifié,
 * puis le compte est retrouvé, lié ou créé par SsoAccountService.
 * reauth : un compte sans mot de passe confirme son identité chez le fournisseur avant une action sensible
 * (activer la 2FA, supprimer son compte), à la place de « Confirm your password ».
 *
 * Toute erreur ramène à la page de connexion avec un message générique ; le détail va dans les logs, sans jeton ni code.
 */
class OidcController extends Controller
{
    public const FAILED = SsoAccountService::FAILED;

    public const NO_ACCOUNT = SsoAccountService::NO_ACCOUNT;

    public const DEACTIVATED = 'This account has been deactivated. Contact your administrator.';

    public const REAUTH_FAILED = 'Your identity could not be confirmed with single sign-on. Try again.';

    public function redirect(Request $request, OidcClient $oidc): RedirectResponse
    {
        abort_unless(OidcClient::enabled(), 404);

        return $this->start($request, $oidc);
    }

    /** Confirmation d'identité d'un compte connecté et lié : reconnexion forcée chez le fournisseur */
    public function reauth(Request $request, OidcClient $oidc): RedirectResponse
    {
        abort_unless(OidcClient::enabled() && $request->user()->oidc_subject !== null, 404);
        // Retour après confirmation : la page qui l'a demandée (« Confirm your password » a déjà mis la sienne)
        if ($request->query('to') === 'two-factor' && ! $request->session()->has('url.intended')) {
            redirect()->setIntendedUrl(route('account.two-factor'));
        }

        return $this->start($request, $oidc, $request->user());
    }

    public function callback(Request $request, OidcClient $oidc, SsoAccountService $accounts): RedirectResponse
    {
        abort_unless(OidcClient::enabled(), 404);

        // Usage unique : retiré de la session quoi qu'il arrive
        $pending = $request->session()->pull('oidc');
        $state = (string) $request->query('state', '');
        $reauth = is_array($pending) && isset($pending['reauth_user']);

        if (! is_array($pending) || $state === '' || ! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            return $this->fail('Invalid or missing state (CSRF, replay or expired session).', reauth: $request->user() !== null);
        }
        if (now()->getTimestamp() - (int) $pending['started_at'] > config('oidc.max_login_seconds')) {
            return $this->fail('Login took too long.', reauth: $reauth);
        }
        // Annulation ou refus chez le fournisseur : seul le code d'erreur OAuth est gardé
        if ($request->filled('error')) {
            return $this->fail('Provider returned an error: '.Str::limit((string) $request->query('error'), 50).'.', reauth: $reauth);
        }
        if (! is_string($request->query('code')) || $request->query('code') === '') {
            return $this->fail('No authorization code.', reauth: $reauth);
        }

        try {
            $claims = $oidc->authenticate($request->query('code'), $pending['verifier'], $pending['nonce']);
        } catch (OidcException $e) {
            return $this->fail($e->getMessage(), reauth: $reauth);
        }

        if ($reauth) {
            return $this->confirmIdentity($request, $pending, $claims);
        }
        // Déjà connecté (autre onglet) : rien à faire
        if ($request->user() !== null) {
            return redirect()->route('dashboard');
        }

        try {
            $user = $accounts->resolve($claims);
        } catch (SsoAccessException $e) {
            if ($e->noAccessEmail !== null) {
                Log::info('SSO sign-in without access', ['reason' => $e->getMessage()]);

                return redirect()->route('oidc.no-access')->with('sso_no_access', $e->noAccessEmail);
            }

            return $this->fail($e->getMessage(), $e->userMessage);
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
        // La personne vient de s'authentifier chez le fournisseur : vaut confirmation, par exemple pour activer la 2FA
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard'));
    }

    /** Personne inconnue en mode invite (ou domaine non autorisé) : aucun compte créé, qui contacter */
    public function noAccess(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get('sso_no_access');
        if (! is_string($email)) {
            return redirect()->route('login');
        }

        return view('auth.sso-no-access', ['email' => $email, 'contact' => config('registration.contact')]);
    }

    private function start(Request $request, OidcClient $oidc, ?User $reauthUser = null): RedirectResponse
    {
        $pending = [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(64),          // PKCE : 43 à 128 caractères non réservés
            'started_at' => now()->getTimestamp(),
            ...($reauthUser !== null ? ['reauth_user' => $reauthUser->id] : []),
        ];

        try {
            $url = $oidc->authorizationUrl($pending['state'], $pending['nonce'], $pending['verifier'], forceLogin: $reauthUser !== null);
        } catch (OidcException $e) {
            return $this->fail($e->getMessage(), reauth: $reauthUser !== null);
        }

        $request->session()->put('oidc', $pending);

        return redirect()->away($url);
    }

    /**
     * Même compte connecté, même identité (iss + sub), et authentification toute récente chez le fournisseur
     * (auth_time, demandé par max_age=0) : l'identité vaut confirmation, comme un mot de passe saisi.
     *
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $claims
     */
    private function confirmIdentity(Request $request, array $pending, array $claims): RedirectResponse
    {
        $user = $request->user();
        if ($user === null || $user->id !== $pending['reauth_user']) {
            return $this->fail('Re-authentication for another session.', reauth: $user !== null);
        }
        if ($claims['iss'] !== $user->oidc_issuer || (string) $claims['sub'] !== $user->oidc_subject) {
            return $this->fail("Re-authentication with another identity (user #{$user->id}).", reauth: true);
        }
        $authTime = $claims['auth_time'] ?? null;
        if (! is_int($authTime) || $authTime < (int) $pending['started_at'] - (int) config('oidc.leeway')) {
            return $this->fail("No fresh authentication at the provider (user #{$user->id}).", reauth: true);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('account.edit'))->with('success', 'Identity confirmed.');
    }

    /** Message générique à l'écran, détail dans les logs ; une confirmation d'identité échouée revient sur « My account » */
    private function fail(string $reason, string $message = self::FAILED, bool $reauth = false): RedirectResponse
    {
        Log::warning($reauth ? 'SSO re-authentication failed' : 'SSO sign-in failed', ['reason' => $reason]);

        if ($reauth && Auth::check()) {
            return redirect()->route('account.edit')->with('error', self::REAUTH_FAILED);
        }

        return redirect()->route('login')->withErrors(['sso' => $message]);
    }
}
