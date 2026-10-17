<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Page « Audit log » (cahier des charges §30 et §44, SCRUM-60) : actions sur les objets de la team,
 * réservée aux Admin (permission audit.view).
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 25;

    /** Lien du menu : la première team dont l'utilisateur peut lire le journal */
    public function current(Request $request): RedirectResponse
    {
        $team = $request->user()->teams()->get()->first(fn (Team $t) => $request->user()->hasPermission(Permission::AuditView, $t));

        abort_if($team === null, 403);

        return redirect()->route('teams.audit.index', $team);
    }

    public function index(Request $request, Team $team): View
    {
        Gate::authorize(Permission::AuditView->value, $team);

        $actions = AuditLog::where('team_id', $team->id)->distinct()->orderBy('action')->pluck('action');
        $authors = User::whereIn('id', AuditLog::where('team_id', $team->id)->whereNotNull('user_id')->select('user_id'))
            ->orderBy('name')->get(['id', 'name', 'email']);

        $filters = $request->validate([
            'action' => ['nullable', Rule::in($actions)],
            'user' => ['nullable', 'integer', Rule::in($authors->pluck('id'))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // Les dates du filtre sont dans le fuseau affiché ; la base est en UTC
        $timezone = config('app.display_timezone');
        $logs = AuditLog::where('team_id', $team->id)
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['user'] ?? null, fn ($q, $user) => $q->where('user_id', $user))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to, $timezone)->endOfDay()->utc()))
            ->with(['user:id,name,email', 'subject'])
            ->latest('created_at')->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('teams.audit', [
            'team' => $team,
            'logs' => $logs,
            'actions' => $actions,
            'authors' => $authors,
            'filters' => $filters,
            'teams' => $request->user()->teams()->orderBy('name')->get()
                ->filter(fn (Team $t) => $request->user()->hasPermission(Permission::AuditView, $t)),
        ]);
    }
}
