<?php

namespace App\Http\Controllers;

use App\Mail\BreakGlassLoginMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Compte de secours (« break-glass », SCRUM-90) : quand le SSO est obligatoire, seul un Super Admin désigné par
 * platform:break-glass se connecte encore par mot de passe, sur cette URL dédiée, puis avec la 2FA de la plateforme.
 * Chaque usage est audité et prévient les autres Super Admin par email. Page absente (404) si le SSO n'est pas
 * obligatoire : la connexion habituelle suffit alors.
 */
class BreakGlassController extends Controller
{
    private const INVALID = 'These credentials do not match an emergency access account.';

    public function show(): View
    {
        abort_unless(OidcClient::required(), 404);

        return view('auth.break-glass');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(OidcClient::required(), 404);
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', Str::lower($data['email']))->first();
        // Même réponse pour un compte inconnu, un mauvais mot de passe ou un compte qui n'est pas de secours
        if ($user === null || ! Hash::check($data['password'], (string) $user->password) || ! $user->isBreakGlass()) {
            throw ValidationException::withMessages(['email' => self::INVALID]);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }

        AuditLog::record('user.login_break_glass', $user, $user, ['email' => $user->email]);
        User::superAdmins()->active()->whereKeyNot($user->getKey())->each(
            fn (User $admin) => Mail::to($admin)->queue(new BreakGlassLoginMail($user, (string) $request->ip(), now()))
        );

        // Repris à la connexion par RecordSignInMethod, y compris après le défi 2FA
        $request->session()->put('auth.pending', ['via' => 'break_glass', 'user_id' => $user->id, 'at' => now()->getTimestamp()]);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }

        // Pas encore de 2FA : EnsureTwoFactorForAdmins impose de la mettre en place avant toute autre page
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
