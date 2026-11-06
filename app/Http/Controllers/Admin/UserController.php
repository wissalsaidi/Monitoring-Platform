<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TeamRole;
use App\Exceptions\AccountStatusException;
use App\Exceptions\PlatformAdminException;
use App\Exceptions\TeamMembershipException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\AccountStatusService;
use App\Services\PlatformAdminService;
use App\Services\TeamMemberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Console d'administration des utilisateurs (SCRUM-84), réservée aux Super Admin (platform.users.manage,
 * vérifié par la route). Qui a accès à quoi, et les moyens de couper un accès tout de suite.
 */
class UserController extends Controller
{
    public const PER_PAGE = 25;

    public const STATUSES = ['active' => 'Active', 'deactivated' => 'Deactivated', 'invited' => 'Invited'];

    public function __construct(
        private TeamMemberService $members,
        private AccountStatusService $accounts,
        private PlatformAdminService $admins,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'team' => ['nullable', 'integer', Rule::exists('teams', 'id')],
        ]);
        $q = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? null;
        $teamId = $filters['team'] ?? null;
        $like = '%'.addcslashes($q, '%_\\').'%';

        // « Invité » : une invitation en attente pour une adresse qui n'a pas encore de compte
        $invitations = TeamInvitation::query()->whereNotIn('email', User::select('email'));

        if ($status === 'invited') {
            $invitations = $invitations->with(['team', 'inviter:id,name'])
                ->when($q !== '', fn (Builder $query) => $query->where('email', 'like', $like))
                ->when($teamId, fn (Builder $query) => $query->where('team_id', $teamId))
                ->orderByDesc('created_at')->paginate(self::PER_PAGE)->withQueryString();
        } else {
            $users = User::query()->with('teams:id,name')
                ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)))
                ->when($status === 'active', fn (Builder $query) => $query->active())
                ->when($status === 'deactivated', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
                ->when($teamId, fn (Builder $query) => $query->whereHas('teams', fn (Builder $t) => $t->whereKey($teamId)))
                ->orderBy('name')->orderBy('id')
                ->paginate(self::PER_PAGE)->withQueryString();
        }

        return view('admin.users.index', [
            'users' => $users ?? null,
            'invitations' => $status === 'invited' ? $invitations : null,
            'counts' => [
                'all' => User::count(),
                'active' => User::active()->count(),
                'deactivated' => User::whereNotNull('deactivated_at')->count(),
                'invited' => $status === 'invited' ? $invitations->total() : $invitations->count(),
            ],
            'teams' => Team::orderBy('name')->orderBy('id')->get(['id', 'name']),
            'teamLabels' => $this->teamLabels(),
            'roles' => TeamRole::cases(),
            'q' => $q,
            'status' => $status,
            'teamId' => $teamId,
        ]);
    }

    public function show(User $user): View
    {
        $user->load('teams');

        return view('admin.users.show', [
            'user' => $user,
            'otherTeams' => Team::whereNotIn('id', $user->teams->pluck('id'))->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'teamLabels' => $this->teamLabels(),
            'roles' => TeamRole::cases(),
            // Teams dont il est le seul Admin actif : ni rétrogradé, ni retiré, ni désactivé
            'soleAdminOf' => $user->teams->filter(fn (Team $team) => $team->pivot->role === TeamRole::Admin
                && ! $team->members()->active()->wherePivot('role', TeamRole::Admin->value)->whereKeyNot($user->id)->exists())
                ->pluck('id'),
            'lastSuperAdmin' => $this->admins->isLastSuperAdmin($user),
            'pendingInvitations' => TeamInvitation::with('team')->where('email', $user->email)->get(),
            'activity' => AuditLog::with('user:id,name')
                ->where(fn (Builder $query) => $query->where('user_id', $user->id)
                    ->orWhere(fn (Builder $s) => $s->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)))
                ->latest('id')->limit(10)->get(),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'team_id' => ['required', 'integer', Rule::exists('teams', 'id')],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);
        $team = Team::findOrFail($data['team_id']);

        try {
            $this->members->invite($team, $data['email'], TeamRole::from($data['role']), $request->user());
        } catch (TeamMembershipException $e) {
            return back()->withInput()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('success', 'Invitation sent to '.Str::lower($data['email'])." for the {$team->name} team.");
    }

    public function addToTeam(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'team_id' => ['required', 'integer', Rule::exists('teams', 'id')],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);
        $team = Team::findOrFail($data['team_id']);

        return $this->attempt(fn () => $this->members->add($team, $user, TeamRole::from($data['role']), $request->user()),
            "{$user->name} was added to the {$team->name} team as ".ucfirst($data['role']).'.');
    }

    public function updateRole(Request $request, User $user, Team $team): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::enum(TeamRole::class)]]);

        return $this->attempt(fn () => $this->members->changeRole($team, $user, TeamRole::from($data['role']), $request->user()),
            "{$user->name} is now ".ucfirst($data['role'])." in {$team->name}.");
    }

    public function removeFromTeam(Request $request, User $user, Team $team): RedirectResponse
    {
        return $this->attempt(fn () => $this->members->remove($team, $user, $request->user()),
            "{$user->name} was removed from the {$team->name} team.");
    }

    public function promote(Request $request, User $user): RedirectResponse
    {
        if (! $user->isActive()) {
            return back()->with('error', 'Reactivate the account before making it a Super Admin.');
        }
        $this->admins->grant($user, $request->user());

        return back()->with('success', "{$user->name} is now a Super Admin.");
    }

    public function demote(Request $request, User $user): RedirectResponse
    {
        return $this->attempt(fn () => $this->admins->revoke($user, $request->user()), "{$user->name} is no longer a Super Admin.");
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        return $this->attempt(fn () => $this->accounts->deactivate($user, $request->user()),
            "{$user->name} was deactivated: sessions closed, Grafana access removed, no more notifications.");
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        return $this->attempt(fn () => $this->accounts->reactivate($user, $request->user()), "{$user->name} was reactivated.");
    }

    public function forcePasswordReset(Request $request, User $user): RedirectResponse
    {
        return $this->attempt(fn () => $this->accounts->forcePasswordReset($user, $request->user()),
            "The password of {$user->name} no longer works. A reset link was sent to {$user->email}.");
    }

    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        return $this->attempt(fn () => $this->accounts->resetTwoFactor($user, $request->user()),
            "Two-factor authentication of {$user->name} was reset. They will set it up again at their next login.");
    }

    /**
     * Nom affiché de chaque team ; deux teams du même nom (anciennes teams « Default ») sont distinguées par leur id.
     *
     * @return Collection<int, string>
     */
    private function teamLabels(): Collection
    {
        $names = Team::pluck('name', 'id');
        $duplicated = $names->duplicates();

        return $names->map(fn (string $name, int $id) => $duplicated->contains($name) ? "{$name} #{$id}" : $name);
    }

    /** Action d'un service : son refus (garde-fou) devient un message d'erreur sur la page */
    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (AccountStatusException|PlatformAdminException|TeamMembershipException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
