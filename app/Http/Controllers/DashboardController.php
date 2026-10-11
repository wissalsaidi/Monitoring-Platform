<?php

namespace App\Http\Controllers;

use App\Enums\TargetStatus;
use App\Models\MonitoringTarget;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $targets = MonitoringTarget::visibleTo($request->user());

        // Les statuts seront mis à jour par la synchro Zabbix (SCRUM-40) ; en attendant, tout est « unknown ».
        $byStatus = (clone $targets)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('dashboard', [
            'total' => $byStatus->sum(),
            'counts' => collect(TargetStatus::cases())->mapWithKeys(fn (TargetStatus $s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            'recentTargets' => $targets->latest()->limit(8)->get(),
            'canCreate' => ($team = $request->user()->defaultTeam()) && $request->user()->can('create', [MonitoringTarget::class, $team]),
        ]);
    }
}
