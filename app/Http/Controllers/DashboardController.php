<?php

namespace App\Http\Controllers;

use App\Enums\TargetStatus;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard principal (cahier des charges §20, SCRUM-43). Lit uniquement la base, tenue à jour
 * par la synchro Zabbix (SCRUM-40) : quelques requêtes agrégées, aucun appel Zabbix.
 */
class DashboardController extends Controller
{
    public const RECENT_ALERTS = 8;

    public const TARGETS_SHOWN = 8;

    public function __invoke(Request $request): View
    {
        $targetIds = MonitoringTarget::visibleTo($request->user())->select('id');

        $byStatus = MonitoringTarget::visibleTo($request->user())->toBase()
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('dashboard', [
            'total' => (int) $byStatus->sum(),
            'counts' => collect(TargetStatus::cases())->mapWithKeys(fn (TargetStatus $s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            'activeAlerts' => Alert::active()->whereIn('monitoring_target_id', $targetIds)->count(),
            'recentAlerts' => Alert::whereIn('monitoring_target_id', $targetIds)
                ->with(['target.team', 'acknowledger:id,name'])
                ->orderByDesc('started_at')->orderByDesc('id')
                ->limit(self::RECENT_ALERTS)->get(),
            // Les targets en difficulté d'abord : critical, warning, unknown, puis up
            'targets' => MonitoringTarget::visibleTo($request->user())
                ->orderByRaw("case status when 'critical' then 0 when 'warning' then 1 when 'unknown' then 2 else 3 end")
                ->orderBy('name')
                ->limit(self::TARGETS_SHOWN)->get(),
            'canCreate' => ($team = $request->user()->defaultTeam()) && $request->user()->can('create', [MonitoringTarget::class, $team]),
        ]);
    }
}
