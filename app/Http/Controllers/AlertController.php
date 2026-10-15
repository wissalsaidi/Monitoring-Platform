<?php

namespace App\Http\Controllers;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Alertes actives et historique des alertes résolues (cahier des charges §19, SCRUM-41).
 */
class AlertController extends Controller
{
    public const PER_PAGE = 20;

    public function active(Request $request): View
    {
        return $this->list($request, history: false);
    }

    public function history(Request $request): View
    {
        return $this->list($request, history: true);
    }

    private function list(Request $request, bool $history): View
    {
        $targets = MonitoringTarget::visibleTo($request->user())->orderBy('name')->get(['id', 'name']);

        $filters = $request->validate([
            'severity' => ['nullable', Rule::enum(AlertSeverity::class)],
            // Seules les targets visibles sont acceptées : impossible de filtrer sur la target d'une autre team.
            'target' => ['nullable', 'integer', Rule::in($targets->modelKeys())],
        ]);

        $visible = fn (): Builder => Alert::whereIn('monitoring_target_id', $targets->modelKeys());

        $alerts = $visible()
            ->when($history, fn ($q) => $q->where('status', AlertStatus::Resolved), fn ($q) => $q->active())
            ->when($filters['severity'] ?? null, fn ($q, $severity) => $q->where('severity', $severity))
            ->when($filters['target'] ?? null, fn ($q, $target) => $q->where('monitoring_target_id', $target))
            ->with('target:id,name')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('alerts.index', [
            'alerts' => $alerts,
            'history' => $history,
            'filters' => $filters,
            'targets' => $targets,
            'severities' => AlertSeverity::cases(),
            'counts' => [
                'active' => $visible()->active()->count(),
                'history' => $visible()->where('status', AlertStatus::Resolved)->count(),
            ],
        ]);
    }
}
