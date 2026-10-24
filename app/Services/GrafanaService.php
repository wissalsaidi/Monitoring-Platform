<?php

namespace App\Services;

use App\Models\MonitoringTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Liens vers le dashboard Grafana générique « Target overview » (SCRUM-46 / SCRUM-47, cahier des charges §39).
 * L'application ne lit aucune donnée dans Grafana : elle vérifie seulement qu'il répond avant d'y envoyer l'utilisateur.
 */
class GrafanaService
{
    /** État de Grafana gardé en cache : plusieurs clics de suite ne refont pas l'appel */
    private const HEALTH_CACHE_SECONDS = 30;

    /** Le dashboard générique ne montre que les checks à temps de réponse (API, site web, base de données). */
    public function supports(MonitoringTarget $target): bool
    {
        return ($target->provisioner()->statusItemKeys()['response_time'] ?? null) !== null;
    }

    /**
     * URL du dashboard, déjà filtré sur la team et la target (variables du dashboard = noms Zabbix), et sur la période.
     *
     * @param  string  $period  période des graphiques de la page (MetricsService::PERIODS) : 1h, 24h, 7d
     */
    public function dashboardUrl(MonitoringTarget $target, string $period): string
    {
        $query = http_build_query([
            'orgId' => 1,
            'var-team' => $target->team->zabbixHostGroupName(),
            'var-target' => $target->zabbixVisibleName(),
            'from' => "now-{$period}",
            'to' => 'now',
        ], '', '&', PHP_QUERY_RFC3986);

        return sprintf('%s/d/%s?%s', rtrim(config('services.grafana.url'), '/'), config('services.grafana.dashboard_uid'), $query);
    }

    /** Grafana répond-il (GET /api/health, base de données de Grafana « ok ») ? */
    public function isAvailable(): bool
    {
        return Cache::remember('grafana:available', self::HEALTH_CACHE_SECONDS, function () {
            try {
                $response = Http::timeout(config('services.grafana.timeout'))
                    ->acceptJson()
                    ->get(rtrim(config('services.grafana.internal_url'), '/').'/api/health');

                if ($response->ok() && $response->json('database') === 'ok') {
                    return true;
                }

                Log::warning('Grafana is unavailable', ['status' => $response->status()]);
            } catch (ConnectionException $e) {
                Log::warning('Grafana is unreachable', ['error' => $e->getMessage()]);
            }

            return false;
        });
    }
}
