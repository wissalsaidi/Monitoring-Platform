<?php

namespace App\Http\Controllers;

use App\Exceptions\PlatformAdminException;
use App\Exceptions\TeamMembershipException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Services\AccountDeletionService;
use App\Services\PlatformAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Page « My account » et suppression du compte (RGPD-5, SCRUM-63).
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
        ]);
    }

    public function destroy(Request $request, AccountDeletionService $deletion): RedirectResponse
    {
        $request->validateWithBag('deleteAccount', [
            'password' => ['required', 'string', 'current_password'],
        ]);

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
}
