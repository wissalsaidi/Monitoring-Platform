<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Page « Two-factor authentication » de « My account » (SCRUM-86). Les actions (activer, confirmer, désactiver,
 * nouveaux codes de secours) sont les routes de Fortify, protégées par la confirmation du mot de passe.
 */
class TwoFactorController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $status = session('status');

        return view('account.two-factor', [
            'user' => $user,
            'required' => $user->requiresTwoFactor(),
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            // Activée mais pas encore confirmée : QR code et clé à saisir dans l'application
            'pending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            // Codes de secours montrés juste après la confirmation ou une régénération, jamais ensuite
            'showRecoveryCodes' => in_array($status, ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true),
        ]);
    }

    /**
     * Mise en place en une étape : mot de passe vérifié puis secret créé (action de Fortify). La route de Fortify
     * passe par la page « Confirm your password », qui renvoie ensuite sur la page sans avoir activé : un clic de trop.
     */
    public function enable(Request $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password']]);

        $enable($request->user());
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->route('account.two-factor')->with('status', 'two-factor-authentication-enabled');
    }
}
