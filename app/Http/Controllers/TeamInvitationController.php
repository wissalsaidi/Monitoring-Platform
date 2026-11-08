<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\TeamRole;
use App\Exceptions\TeamMembershipException;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\OidcClient;
use App\Services\TeamMemberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Invitations à rejoindre une team (SCRUM-59) : envoi et annulation par un Admin de la team ou un Super Admin
 * (gate team.invite, SCRUM-83), acceptation par l'invité. Le lien est la porte d'entrée de la plateforme :
 * il crée le compte d'une adresse inconnue (SCRUM-83), quel que soit REGISTRATION_MODE.
 */
class TeamInvitationController extends Controller
{
    use PasswordValidationRules;

    private const INVALID = 'This invitation link is no longer valid. It may have expired, been used or been cancelled. Ask a team Admin to send a new one.';

    public function __construct(private TeamMemberService $members) {}

    public function store(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('team.invite', $team);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        try {
            $this->members->invite($team, $data['email'], TeamRole::from($data['role']), $request->user());
        } catch (TeamMembershipException $e) {
            return back()->withInput()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('success', 'Invitation sent to '.Str::lower($data['email']).'.');
    }

    public function destroy(Request $request, Team $team, TeamInvitation $invitation): RedirectResponse
    {
        Gate::authorize('team.invite', $team);
        abort_unless($invitation->team_id === $team->id, 404);

        $this->members->cancelInvitation($invitation, $request->user());

        return back()->with('success', "Invitation to {$invitation->email} cancelled.");
    }

    /**
     * Lien reçu par email (route publique) :
     * - connecté avec l'adresse invitée : il rejoint la team ;
     * - non connecté, compte existant : connexion, puis retour ici (URL « intended ») ;
     * - non connecté, adresse inconnue : page « Create your account », email prérempli et non modifiable.
     */
    public function accept(Request $request, int $invitation): View|RedirectResponse
    {
        $invitation = $this->validInvitation($request, $invitation);
        if ($invitation === null) {
            return view('invitations.invalid', ['message' => self::INVALID]);
        }

        if ($user = $request->user()) {
            if (Str::lower($user->email) !== $invitation->email) {
                return view('invitations.invalid', [
                    'message' => "This invitation was sent to {$invitation->email}. You are logged in as {$user->email}. Log out and sign in with the invited address.",
                ]);
            }

            // Le lien prouve que l'adresse lui appartient : plus besoin de l'email de vérification
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
            $this->members->accept($invitation, $user);

            return redirect()->route('dashboard')
                ->with('success', "You joined the {$invitation->team->name} team as ".ucfirst($invitation->role->value).'.');
        }

        if (User::where('email', $invitation->email)->exists()) {
            return redirect()->guest(route('login'));
        }
        // SSO obligatoire (SCRUM-90) : le compte est créé à la connexion SSO, qui accepte l'invitation (SCRUM-89)
        if (OidcClient::required()) {
            return view('invitations.sso', ['invitation' => $invitation]);
        }

        return view('invitations.register', ['invitation' => $invitation, 'action' => $request->fullUrl()]);
    }

    /** Création du compte depuis le lien (SCRUM-83) : email vérifié d'office, la personne arrive dans la team invitée */
    public function register(Request $request, int $invitation): View|RedirectResponse
    {
        abort_if(OidcClient::required(), 404);
        $invitation = $this->validInvitation($request, $invitation);
        if ($invitation === null) {
            return view('invitations.invalid', ['message' => self::INVALID]);
        }

        // Compte créé entre-temps (autre onglet, autre invitation) : on se connecte et on revient sur le lien
        if (User::where('email', $invitation->email)->exists()) {
            redirect()->setIntendedUrl($request->fullUrl());   // guest() ne garde l'URL que pour un GET

            return redirect()->route('login')->with('status', 'An account already exists for this address. Log in to accept the invitation.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        $user = DB::transaction(function () use ($data, $invitation) {
            $user = User::forceCreate([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => Hash::make($data['password']),
                'email_verified_at' => now(),
            ]);
            AuditLog::record('user.create', $user, $user, [
                'email' => $user->email,
                'via' => 'invitation',
                'team' => $invitation->team->name,
            ]);
            $this->members->accept($invitation, $user);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->with('success', "Welcome! You joined the {$invitation->team->name} team as ".ucfirst($invitation->role->value).'.');
    }

    /** Invitation existante, lien signé intact et non expiré ; sinon null (message unique, sans dire pourquoi) */
    private function validInvitation(Request $request, int $id): ?TeamInvitation
    {
        $invitation = TeamInvitation::with('team')->find($id);

        return $invitation !== null && $request->hasValidSignature() && ! $invitation->isExpired() ? $invitation : null;
    }
}
