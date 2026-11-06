<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TeamRole;
use App\Exceptions\TeamMembershipException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamAdminService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Console d'administration des teams (SCRUM-85), réservée aux Super Admin (platform.teams.manage, vérifié par la
 * route) : les teams sont créées par la DSI avec leur premier Admin, renommées et supprimées ici.
 */
class TeamController extends Controller
{
    public function __construct(private TeamAdminService $teams) {}

    public function index(): View
    {
        return view('admin.teams.index', [
            'teams' => Team::query()
                ->withCount(['members', 'monitoringTargets', 'alerts as active_alerts_count' => fn (Builder $query) => $query->active()])
                ->with(['members' => fn ($query) => $query->wherePivot('role', TeamRole::Admin->value)->orderBy('name')])
                ->orderBy('name')->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('teams', 'name')],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
        ], [], ['admin_email' => 'first Admin']);

        // Premier Admin : un compte existant est ajouté tout de suite, une adresse inconnue est invitée
        $email = Str::lower(trim($data['admin_email']));
        $admin = User::where('email', $email)->first();
        if ($admin !== null && ! $admin->isActive()) {
            return back()->withInput()->withErrors(['admin_email' => 'This account is deactivated. Reactivate it first, or choose someone else.']);
        }

        [$team, $warnings] = $this->teams->create(trim($data['name']), $admin, $admin === null ? $email : null, $request->user());

        return redirect()->route('admin.teams.show', $team)
            ->with('success', "Team {$team->name} created. ".($admin !== null ? "{$admin->name} is its Admin." : "An invitation was sent to {$email} as Admin."))
            ->with('warning', implode(' ', $warnings) ?: null);
    }

    public function show(Team $team): View
    {
        return view('admin.teams.show', [
            'team' => $team->loadCount(['monitoringTargets', 'alerts as active_alerts_count' => fn (Builder $query) => $query->active()]),
            'members' => $team->members()->orderByRaw("case team_members.role when 'admin' then 0 when 'manager' then 1 else 2 end")->orderBy('name')->get(),
            'invitations' => $team->invitations()->orderBy('email')->get(),
        ]);
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('teams', 'name')->ignore($team->id)],
        ]);

        $old = $team->name;
        $warnings = $this->teams->rename($team, trim($data['name']), $request->user());

        return back()
            ->with('success', $old === $team->name ? 'No change.' : "Team {$old} renamed to {$team->name}.")
            ->with('warning', implode(' ', $warnings) ?: null);
    }

    public function destroy(Request $request, Team $team): RedirectResponse
    {
        $data = $request->validate(['confirm_name' => ['required', 'string']]);
        $name = $team->name;

        try {
            $this->teams->delete($team, $data['confirm_name'], $request->user());
        } catch (TeamMembershipException $e) {
            return back()->withErrors(['confirm_name' => $e->getMessage()]);
        } catch (ZabbixException $e) {
            report($e);

            return back()->with('error', 'Zabbix could not remove one of the targets, so the team was not deleted. Try again in a moment.');
        }

        return redirect()->route('admin.teams.index')->with('success', "Team {$name} deleted, with its targets and its Grafana organization.");
    }
}
