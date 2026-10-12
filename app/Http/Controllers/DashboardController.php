<?php

namespace App\Http\Controllers;

use App\Enums\TargetStatus;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $targets = MonitoringTarget::visibleTo($request->user());

        // Statuts tenus à jour par la synchro Zabbix (SCRUM-40)
        $byStatus = (clone $targets)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('dashboard', [
            'total' => $byStatus->sum(),
            'counts' => collect(TargetStatus::cases())->mapWithKeys(fn (TargetStatus $s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            'activeAlerts' => Alert::active()->whereIn('monitoring_target_id', (clone $targets)->select('id'))->count(),
            'recentTargets' => $targets->latest()->limit(8)->get(),
            'canCreate' => ($team = $request->user()->defaultTeam()) && $request->user()->can('create', [MonitoringTarget::class, $team]),
        ]);
    }
}
