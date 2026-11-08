<?php

namespace App\Http\Controllers;

use App\Exceptions\PlatformAdminException;
use App\Exceptions\TeamMembershipException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\AuditLog;
use App\Services\AccountDeletionService;
use App\Services\PlatformAdminService;
use App\Services\SsoAccountService;
use App\Support\IdentityConfirmation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Page « My account » et suppression du compte (RGPD-5, SCRUM-63), identité SSO liée (SCRUM-89).
 */
class AccountController extends Controller
{
    public function edit(Request $request, AccountDeletionService $deletion): View
    {
        return view('account.edit', [
            'user' => $request->user(),
            'teams' => $request->user()->teams()->orderBy('name')->withCount('members')->get(),
            'blockingTeams' => $deletion->blockingTeams($request->user()),
            'lastSuperAdmin' => app(PlatformAdminService::class)->isLastSuperAdmin($request->user()),
            'ssoProvider' => SsoAccountService::providerName($request->user()->oidc_issuer),
            'identityConfirmed' => IdentityConfirmation::isRecent(),
        ]);
    }

    public function destroy(Request $request, AccountDeletionService $deletion): RedirectResponse
    {
        // Compte SSO sans mot de passe (SCRUM-89) : identité confirmée récemment chez le fournisseur
        if ($request->user()->hasPassword()) {
            $request->validateWithBag('deleteAccount', [
                'password' => ['required', 'string', 'current_password'],
            ]);
        } elseif (! IdentityConfirmation::isRecent()) {
            return back()->with('error', 'Confirm your identity with single sign-on first.');
        }

        try {
            $deletion->delete($request->user());
        } catch (TeamMembershipException|PlatformAdminException $e) {
            return back()->with('error', $e->getMessage());
        } catch (ZabbixException $e) {
            return back()->with('error', $e->userMessage().' Your account was not deleted, please try again.');
        }

        // logout() renouvelle le jeton « remember me » avec save() : sur un modèle supprimé, cela recréerait le compte
        $request->user()->setRememberToken(null);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your account and your personal data have been deleted.');
    }

    /**
     * Délier le SSO : la connexion se fait ensuite par mot de passe seulement. Refusé sans mot de passe local, sinon
     * le compte deviendrait inaccessible. Une nouvelle connexion SSO pourra le relier (email attesté).
     */
    public function unlinkSso(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasSso()) {
            return back();
        }
        if (! $user->hasPassword()) {
            return back()->with('error', 'Your account has no password: single sign-on is your only way to log in, so it cannot be unlinked.');
        }
        $request->validateWithBag('unlinkSso', [
            'password' => ['required', 'string', 'current_password'],
        ]);

        $provider = SsoAccountService::providerName($user->oidc_issuer);
        $user->forceFill(['oidc_issuer' => null, 'oidc_subject' => null])->save();
        AuditLog::record('user.sso_unlink', $user, $user, ['provider' => $provider]);

        return back()->with('success', 'Single sign-on unlinked. Log in with your email and password from now on.');
    }
}
