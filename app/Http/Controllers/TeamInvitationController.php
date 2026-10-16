<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Exceptions\TeamMembershipException;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\TeamMemberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Invitations à rejoindre une team (SCRUM-59) : envoi et annulation par un Admin, acceptation par l'invité.
 */
class TeamInvitationController extends Controller
{
    public function __construct(private TeamMemberService $members) {}

    public function store(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize(Permission::UserCreate->value, $team);
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
        Gate::authorize(Permission::UserCreate->value, $team);
        abort_unless($invitation->team_id === $team->id, 404);

        $this->members->cancelInvitation($invitation, $request->user());

        return back()->with('success', "Invitation to {$invitation->email} cancelled.");
    }

    /**
     * Lien reçu par email. Un invité non connecté passe par la connexion (ou l'inscription puis la
     * vérification de son email) et revient ici grâce à l'URL « intended ».
     */
    public function accept(Request $request, int $invitation): View|RedirectResponse
    {
        $invitation = TeamInvitation::with('team')->find($invitation);

        if ($invitation === null || ! $request->hasValidSignature() || $invitation->isExpired()) {
            return view('invitations.invalid', [
                'message' => 'This invitation link is no longer valid. It may have expired, been used or been cancelled. Ask a team Admin to send a new one.',
            ]);
        }

        if (Str::lower($request->user()->email) !== $invitation->email) {
            return view('invitations.invalid', [
                'message' => "This invitation was sent to {$invitation->email}. You are logged in as {$request->user()->email}. Log out and sign in with the invited address.",
            ]);
        }

        $this->members->accept($invitation, $request->user());

        return redirect()->route('dashboard')
            ->with('success', "You joined the {$invitation->team->name} team as ".ucfirst($invitation->role->value).'.');
    }
}
