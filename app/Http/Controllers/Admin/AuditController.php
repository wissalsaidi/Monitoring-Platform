<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Audit de toute la plateforme (SCRUM-85) : les entrées de toutes les teams et les actions de plateforme
 * (sans team : comptes, Super Admin, teams supprimées). Réservé aux Super Admin (platform.audit.view).
 */
class AuditController extends Controller
{
    public const PER_PAGE = 50;

    /** Valeur du filtre « team » pour les entrées sans team */
    public const PLATFORM = 'platform';

    public function index(Request $request): View
    {
        $actions = AuditLog::distinct()->orderBy('action')->pluck('action');
        $authors = User::whereIn('id', AuditLog::whereNotNull('user_id')->select('user_id'))->orderBy('name')->get(['id', 'name', 'email']);
        $teams = Team::orderBy('name')->orderBy('id')->get(['id', 'name']);

        $filters = $request->validate([
            'team' => ['nullable', Rule::in([self::PLATFORM, ...$teams->pluck('id')->map(fn ($id) => (string) $id)])],
            'action' => ['nullable', Rule::in($actions)],
            'user' => ['nullable', 'integer', Rule::in($authors->pluck('id'))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // Les dates du filtre sont dans le fuseau affiché ; la base est en UTC
        $timezone = config('app.display_timezone');
        $logs = AuditLog::query()
            ->when($filters['team'] ?? null, fn ($q, $team) => $team === self::PLATFORM ? $q->whereNull('team_id') : $q->where('team_id', (int) $team))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['user'] ?? null, fn ($q, $user) => $q->where('user_id', $user))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to, $timezone)->endOfDay()->utc()))
            ->with(['user:id,name,email', 'team:id,name', 'subject'])
            ->latest('created_at')->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.audit', compact('logs', 'actions', 'authors', 'teams', 'filters'));
    }
}
