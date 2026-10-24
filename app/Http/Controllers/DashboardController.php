<?php

namespace App\Http\Controllers;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Monitoring\ProvisionerRegistry;
use App\Services\StatisticsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard principal (cahier des charges §20, SCRUM-43). Lit uniquement la base, tenue à jour
 * par la synchro Zabbix (SCRUM-40) : quelques requêtes agrégées, aucun appel Zabbix.
 * Filtre par catégorie (§21, SCRUM-72) : ?type=api|website|server|container|database.
 */
class DashboardController extends Controller
{
    public const RECENT_ALERTS = 8;

    public const TARGETS_SHOWN = 8;

    /** Statistiques du dashboard : 7 derniers jours (SCRUM-51) */
    public const SUMMARY_DAYS = 7;

    public function __invoke(Request $request, ProvisionerRegistry $provisioners): View
    {
        $user = $request->user();
        $type = TargetType::tryFrom((string) $request->query('type'));
        $type = $type !== null && $provisioners->supports($type) ? $type : null;

        // Targets visibles, de la catégorie choisie
        $visible = fn (): Builder => MonitoringTarget::visibleTo($user)->when($type, fn (Builder $query) => $query->where('type', $type));
        $targetIds = $visible()->select('id');

        $byStatus = $visible()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $byType = MonitoringTarget::visibleTo($user)->toBase()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');

        return view('dashboard', [
            'type' => $type,
            // Catégories : All + les types supervisables, avec leur nombre de targets
            'categories' => collect($provisioners->supportedTypes())->mapWithKeys(fn (TargetType $t) => [$t->value => (int) ($byType[$t->value] ?? 0)]),
            'allTargets' => (int) $byType->sum(),
            'total' => (int) $byStatus->sum(),
            'counts' => collect(TargetStatus::cases())->mapWithKeys(fn (TargetStatus $s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            'activeAlerts' => Alert::active()->whereIn('monitoring_target_id', $targetIds)->count(),
            'recentAlerts' => Alert::whereIn('monitoring_target_id', $targetIds)
                ->with(['target.team', 'acknowledger:id,name'])
                ->orderByDesc('started_at')->orderByDesc('id')
                ->limit(self::RECENT_ALERTS)->get(),
            // Les targets en difficulté d'abord : critical, warning, unknown, puis up
            'targets' => $visible()
                ->orderByRaw("case status when 'critical' then 0 when 'warning' then 1 when 'unknown' then 2 else 3 end")
                ->orderBy('name')
                ->limit(self::TARGETS_SHOWN)->get(),
            // 7 derniers jours, précalculés chaque heure (SCRUM-51)
            'summary' => app(StatisticsService::class)->summary($targetIds, self::SUMMARY_DAYS),
            'canCreate' => ($team = $user->defaultTeam()) && $user->can('create', [MonitoringTarget::class, $team]),
        ]);
    }
}
