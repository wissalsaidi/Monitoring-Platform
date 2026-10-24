<?php

namespace App\Http\Controllers;

use App\Models\MonitoringTarget;
use App\Services\GrafanaService;
use App\Services\MetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Bouton « Open Grafana dashboard » de la page d'une target (SCRUM-47) : GET /targets/{target}/grafana?period=1h|24h|7d.
 * Redirige vers le dashboard générique filtré sur la target ; Grafana indisponible : retour à la page avec un message
 * générique (§47), le détail est dans les logs.
 */
class TargetGrafanaController extends Controller
{
    public function __invoke(Request $request, MonitoringTarget $target, GrafanaService $grafana): RedirectResponse
    {
        Gate::authorize('view', $target);
        abort_unless($grafana->supports($target), 404);

        $period = $request->validate([
            'period' => ['sometimes', Rule::in(array_keys(MetricsService::PERIODS))],
        ])['period'] ?? '1h';

        if (! $grafana->isAvailable()) {
            return redirect()->route('targets.show', $target)->with('warning', 'Advanced metrics are temporarily unavailable.');
        }

        return redirect()->away($grafana->dashboardUrl($target, $period));
    }
}
