<?php

namespace App\Services;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Support\MetricRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Onglet History d'une target (SCRUM-50) : disponibilité sur 24 h, 7 j et 30 j, et valeur maximale atteinte pendant
 * chaque incident. Rien d'inventé : null (affiché « — ») quand Zabbix n'a aucune mesure, ou ne répond pas.
 */
class IncidentHistoryService
{
    /** Périodes de la disponibilité affichée en haut de l'onglet */
    public const AVAILABILITY_PERIODS = ['24h', '7d', '30d'];

    /** true si Zabbix n'a pas répondu pendant le dernier calcul : la page le signale */
    public bool $zabbixFailed = false;

    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * Disponibilité en % sur chaque période (moyenne de la série « availability », SCRUM-49) ; null sans mesure.
     *
     * @return array<string, ?float> ['24h' => 99.8, '7d' => 100.0, '30d' => null]
     */
    public function availability(MonitoringTarget $target): array
    {
        $availability = [];

        foreach (self::AVAILABILITY_PERIODS as $period) {
            try {
                $stats = $this->metrics->series($target, 'availability', $period)['stats'];
                $availability[$period] = $stats['count'] > 0 ? $stats['avg'] : null;
            } catch (ZabbixException $e) {
                $this->zabbixFailed = true;
                $availability[$period] = null;
            }
        }

        return $availability;
    }

    /**
     * Valeur maximale de chaque incident, dans l'unité de la valeur de l'alerte : le max des mesures de Zabbix entre
     * le début et la fin (ou maintenant), et de la valeur relevée au déclenchement. Une fois l'incident résolu, elle
     * est enregistrée (peak_value) et n'est plus relue.
     *
     * @param  Collection<int, Alert>  $alerts
     * @return array<int, ?float> id de l'alerte → maximum, null si la métrique n'en a pas ou sans mesure
     */
    public function peaks(MonitoringTarget $target, Collection $alerts): array
    {
        $peaks = [];

        foreach ($alerts as $alert) {
            $peaks[$alert->id] = $alert->peak_value ?? $this->measurePeak($target, $alert);
        }

        return $peaks;
    }

    private function measurePeak(MonitoringTarget $target, Alert $alert): ?float
    {
        $series = $alert->metric !== null ? $target->provisioner()->incidentSeries($alert->metric) : null;
        if ($series === null) {
            return null;
        }

        $end = $alert->resolved_at ?? now();

        try {
            $measured = $this->metrics->series($target, $series, MetricRange::window($alert->started_at->timestamp, $end->timestamp))['stats']['max'];
        } catch (ZabbixException $e) {
            $this->zabbixFailed = true;
            Log::channel('zabbix')->warning('Peak of an incident not read', ['alert_id' => $alert->id, 'error' => $e->getMessage()]);

            return null;
        }

        $peak = collect([$measured, $alert->value])->filter(fn ($value) => $value !== null)->max();

        // Incident terminé : sa valeur maximale ne changera plus
        if ($peak !== null && $alert->resolved_at !== null) {
            $alert->forceFill(['peak_value' => $peak])->saveQuietly();
        }

        return $peak;
    }
}
