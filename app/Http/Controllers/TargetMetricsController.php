<?php

namespace App\Http\Controllers;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Services\MetricsService;
use App\Support\MetricRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * API interne des graphiques (SCRUM-44, toutes les séries d'un type en SCRUM-72) : JSON lu par le JavaScript
 * de la page d'une target. GET /targets/{target}/metrics/{metric}?period=1h|24h|7d…, ou ?from=…&to=… (SCRUM-49)
 */
class TargetMetricsController extends Controller
{
    /** Ancienne URL du temps de réponse (SCRUM-44), gardée */
    public function responseTime(Request $request, MonitoringTarget $target, MetricsService $metrics): JsonResponse
    {
        return $this->show($request, $target, 'response_time', $metrics);
    }

    public function show(Request $request, MonitoringTarget $target, string $metric, MetricsService $metrics): JsonResponse
    {
        Gate::authorize('view', $target);

        // Série inconnue pour ce type (ex. CPU d'une API) : 404, sans appel Zabbix
        abort_unless(array_key_exists($metric, $target->provisioner()->chartSeries()), 404);

        // ?period=15m|1h|6h|24h|7d|30d, ou ?from=AAAA-MM-JJ&to=AAAA-MM-JJ (SCRUM-49) ; 422 si invalide
        $range = MetricRange::fromRequest($request);

        try {
            return response()->json($metrics->series($target, $metric, $range));
        } catch (ZabbixException $e) {
            // Message générique (§47) ; le détail est dans storage/logs/zabbix-*.log
            return response()->json(['message' => $e->userMessage()], 503);
        }
    }
}
