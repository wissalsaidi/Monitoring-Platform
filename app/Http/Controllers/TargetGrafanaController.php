<?php

namespace App\Http\Controllers;

use App\Exceptions\GrafanaException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Services\GrafanaAccessService;
use App\Services\GrafanaService;
use App\Support\MetricRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Bouton « Open Grafana dashboard » de la page d'une target (SCRUM-47) : GET /targets/{target}/grafana?period=…, ou ?from=…&to=…
 * Donne d'abord à l'utilisateur l'accès en lecture à l'organisation Grafana de la team (SCRUM-48), puis redirige vers
 * le dashboard générique filtré sur la target. Grafana indisponible : retour à la page avec un message générique (§47),
 * le détail est dans les logs.
 */
class TargetGrafanaController extends Controller
{
    private const UNAVAILABLE = 'Advanced metrics are temporarily unavailable.';

    public function __invoke(Request $request, MonitoringTarget $target, GrafanaService $grafana, GrafanaAccessService $access): RedirectResponse
    {
        Gate::authorize('view', $target);
        abort_unless($grafana->supports($target), 404);

        // Même période que le graphique de la page : prédéfinie, ou dates choisies (SCRUM-49)
        $range = MetricRange::fromRequest($request);

        if (! $grafana->isAvailable()) {
            return $this->unavailable($target);
        }

        try {
            $orgId = $access->grant($request->user(), $target->team);
        } catch (GrafanaException|ZabbixException $e) {
            Log::warning('Grafana access not granted', ['target_id' => $target->id, 'user_id' => $request->user()->id, 'error' => $e->getMessage()]);

            return $this->unavailable($target);
        }

        return redirect()->away($grafana->dashboardUrl($target, $range, $orgId));
    }

    private function unavailable(MonitoringTarget $target): RedirectResponse
    {
        return redirect()->route('targets.show', $target)->with('warning', self::UNAVAILABLE);
    }
}
