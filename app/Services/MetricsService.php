<?php

namespace App\Services;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Monitoring\Provisioners\ApiProvisioner;
use Illuminate\Support\Facades\Cache;

/**
 * Séries de métriques lues dans Zabbix pour les graphiques (SCRUM-44).
 * Laravel ne stocke pas les mesures brutes (§38) : il les lit à la demande, avec un cache court.
 */
class MetricsService
{
    /** Périodes proposées (clé => durée en secondes) */
    public const PERIODS = ['1h' => 3600, '24h' => 86400, '7d' => 604800];

    /** Au-delà, les points sont regroupés (moyenne par intervalle) : un graphique n'affiche pas 20 000 points. */
    public const MAX_POINTS = 360;

    /** Les mesures arrivent toutes les 30 s au mieux : inutile de relire Zabbix plus souvent. */
    private const CACHE_SECONDS = 30;

    public function __construct(private readonly ZabbixService $zabbix) {}

    /**
     * Temps de réponse (ms) d'une target sur une période.
     *
     * @return array{period: string, from: int, to: int, source: string, points: list<array{0: int, 1: float}>,
     *               stats: array{avg: ?float, min: ?float, max: ?float, count: int},
     *               thresholds: array{warning: int, critical: int}}
     *
     * @throws ZabbixException
     */
    public function responseTime(MonitoringTarget $target, string $period): array
    {
        return Cache::remember(
            "metrics:response-time:{$target->id}:{$period}",
            self::CACHE_SECONDS,
            fn () => $this->fetchResponseTime($target, $period),
        );
    }

    private function fetchResponseTime(MonitoringTarget $target, string $period): array
    {
        $to = now()->timestamp;
        $from = $to - self::PERIODS[$period];
        $itemId = $target->zabbix_host_id ? $this->responseTimeItemId($target->zabbix_host_id) : null;

        [$source, $points, $stats] = match (true) {
            $itemId === null => ['none', [], self::stats([])],
            $period === '7d' => $this->fromTrends($itemId, $from) ?? $this->fromHistory($itemId, $from),
            default => $this->fromHistory($itemId, $from),
        };

        return [
            'period' => $period,
            'from' => $from * 1000,
            'to' => $to * 1000,
            'source' => $source,
            'points' => $points,
            'stats' => $stats,
            'thresholds' => ['warning' => $target->warning_threshold_ms, 'critical' => $target->critical_threshold_ms],
        ];
    }

    /** Mesures brutes (history.get), regroupées si elles sont trop nombreuses. */
    private function fromHistory(string $itemId, int $from): array
    {
        $rows = $this->zabbix->getHistory([
            'output' => ['clock', 'value'],
            'itemids' => [$itemId],
            'history' => 0,                // valeurs flottantes
            'time_from' => $from,
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
        ]);

        // Zabbix mesure en secondes ; la plateforme affiche des millisecondes.
        $points = array_map(fn (array $row) => [(int) $row['clock'] * 1000, round((float) $row['value'] * 1000, 3)], $rows);

        return ['history', self::downsample($points), self::stats(array_column($points, 1))];
    }

    /**
     * Tendances horaires (trend.get) pour les longues périodes : min / moyenne / max déjà calculés par Zabbix.
     * Null si Zabbix n'en a pas encore (target récente) : on retombe alors sur l'historique.
     */
    private function fromTrends(string $itemId, int $from): ?array
    {
        $rows = $this->zabbix->getTrends([
            'output' => ['clock', 'num', 'value_min', 'value_avg', 'value_max'],
            'itemids' => [$itemId],
            'time_from' => $from,
        ]);

        if ($rows === []) {
            return null;
        }

        usort($rows, fn ($a, $b) => $a['clock'] <=> $b['clock']);
        $count = array_sum(array_column($rows, 'num'));
        $weighted = array_sum(array_map(fn ($row) => (float) $row['value_avg'] * (int) $row['num'], $rows));

        return [
            'trends',
            array_map(fn (array $row) => [(int) $row['clock'] * 1000, round((float) $row['value_avg'] * 1000, 3)], $rows),
            [
                'avg' => $count > 0 ? round($weighted / $count * 1000, 1) : null,
                'min' => round(min(array_map('floatval', array_column($rows, 'value_min'))) * 1000, 1),
                'max' => round(max(array_map('floatval', array_column($rows, 'value_max'))) * 1000, 1),
                'count' => (int) $count,
            ],
        ];
    }

    /** Id de l'item « temps de réponse » du web scenario ; il ne change pas, on le garde un jour. */
    private function responseTimeItemId(string $hostId): ?string
    {
        $key = ApiProvisioner::responseTimeKey();

        return Cache::remember("zabbix:item:response-time:$hostId", now()->addDay(), fn () => $this->zabbix->getItems([
            'output' => ['itemid'],
            'hostids' => [$hostId],
            'webitems' => true,
            'filter' => ['key_' => [$key]],
        ])[0]['itemid'] ?? null);
    }

    /**
     * Moyenne par intervalle de temps égal, pour garder au plus MAX_POINTS points.
     *
     * @param  list<array{0: int, 1: float}>  $points  triés par date
     * @return list<array{0: int, 1: float}>
     */
    public static function downsample(array $points, int $max = self::MAX_POINTS): array
    {
        if (count($points) <= $max) {
            return $points;
        }

        $first = $points[0][0];
        $width = (end($points)[0] - $first) / $max ?: 1;
        $buckets = [];

        foreach ($points as [$time, $value]) {
            $index = min($max - 1, intdiv($time - $first, (int) ceil($width)));
            $buckets[$index][] = [$time, $value];
        }

        return array_values(array_map(fn (array $bucket) => [
            (int) round(array_sum(array_column($bucket, 0)) / count($bucket)),
            round(array_sum(array_column($bucket, 1)) / count($bucket), 3),
        ], $buckets));
    }

    /** @param list<float> $values */
    private static function stats(array $values): array
    {
        return [
            'avg' => $values ? round(array_sum($values) / count($values), 1) : null,
            'min' => $values ? round(min($values), 1) : null,
            'max' => $values ? round(max($values), 1) : null,
            'count' => count($values),
        ];
    }
}
