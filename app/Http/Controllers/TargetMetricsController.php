<?php

namespace App\Http\Controllers;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * API interne des graphiques (SCRUM-44) : JSON lu par le JavaScript de la page d'une target.
 */
class TargetMetricsController extends Controller
{
    public function responseTime(Request $request, MonitoringTarget $target, MetricsService $metrics): JsonResponse
    {
        Gate::authorize('view', $target);

        $period = $request->validate([
            'period' => ['sometimes', Rule::in(array_keys(MetricsService::PERIODS))],
        ])['period'] ?? '1h';

        try {
            return response()->json($metrics->responseTime($target, $period));
        } catch (ZabbixException $e) {
            // Message générique (§47) ; le détail est dans storage/logs/zabbix-*.log
            return response()->json(['message' => $e->userMessage()], 503);
        }
    }
}
