<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Exceptions\TeamMembershipException;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamMemberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Page « Team members » (cahier des charges §25 et §44, SCRUM-59). Réservée aux Admin de la team (permissions user.*).
 */
class TeamMemberController extends Controller
{
    public function __construct(private TeamMemberService $members) {}

    /** Lien du menu : la première team dont l'utilisateur gère les membres */
    public function current(Request $request): RedirectResponse
    {
        $team = $request->user()->teams()->get()->first(fn (Team $t) => $request->user()->hasPermission(Permission::UserView, $t));

        abort_if($team === null, 403);

        return redirect()->route('teams.members.index', $team);
    }

    public function index(Request $request, Team $team): View
    {
        Gate::authorize(Permission::UserView->value, $team);

        return view('teams.members', [
            'team' => $team,
            'members' => $team->members()->orderBy('name')->get(),
            'invitations' => $team->invitations()->with('inviter:id,name')->latest()->get(),
            'roles' => TeamRole::cases(),
            'adminCount' => $team->members()->wherePivot('role', TeamRole::Admin->value)->count(),
            // Autres teams que l'utilisateur administre : sélecteur en haut de page
            'teams' => $request->user()->teams()->orderBy('name')->get()
                ->filter(fn (Team $t) => $request->user()->hasPermission(Permission::UserView, $t)),
        ]);
    }

    public function update(Request $request, Team $team, User $member): RedirectResponse
    {
        Gate::authorize(Permission::UserUpdate->value, $team);
        $data = $request->validate(['role' => ['required', Rule::enum(TeamRole::class)]]);

        try {
            $this->members->changeRole($team, $member, TeamRole::from($data['role']), $request->user());
        } catch (TeamMembershipException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$member->name} is now ".ucfirst($data['role']).'.');
    }

    public function destroy(Request $request, Team $team, User $member): RedirectResponse
    {
        Gate::authorize(Permission::UserDelete->value, $team);

        try {
            $this->members->remove($team, $member, $request->user());
        } catch (TeamMembershipException $e) {
            return back()->with('error', $e->getMessage());
        }

        // S'être retiré soi-même : plus d'accès à cette page
        return $member->is($request->user())
            ? redirect()->route('dashboard')->with('success', "You left the {$team->name} team.")
            : back()->with('success', "{$member->name} was removed from the team.");
    }
}
