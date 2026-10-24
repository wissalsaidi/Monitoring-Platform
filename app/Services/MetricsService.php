<?php

namespace App\Services;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Séries de métriques lues dans Zabbix pour les graphiques (SCRUM-44, généralisé à tous les types en SCRUM-72).
 * Laravel ne stocke pas les mesures brutes (§38) : il les lit à la demande, avec un cache court.
 * Les séries d'un type sont déclarées par son provisioner (chartSeries) : clé d'item, unité, facteur.
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
     * @throws ZabbixException
     */
    public function responseTime(MonitoringTarget $target, string $period): array
    {
        return $this->series($target, 'response_time', $period);
    }

    /**
     * Une série d'une target sur une période, dans son unité d'affichage.
     *
     * @return array{metric: string, label: string, unit: string, period: string, from: int, to: int, source: string,
     *               points: list<array{0: int, 1: float}>, stats: array{avg: ?float, min: ?float, max: ?float, count: int},
     *               thresholds: array{warning: int|float|null, critical: int|float|null}}
     *
     * @throws ZabbixException
     * @throws InvalidArgumentException série inconnue pour ce type
     */
    public function series(MonitoringTarget $target, string $metric, string $period): array
    {
        $series = $target->provisioner()->chartSeries()[$metric]
            ?? throw new InvalidArgumentException("No \"$metric\" chart for a {$target->type->value} target.");

        return Cache::remember(
            "metrics:{$metric}:{$target->id}:{$period}",
            self::CACHE_SECONDS,
            fn () => $this->fetch($target, $metric, $series, $period),
        );
    }

    private function fetch(MonitoringTarget $target, string $metric, array $series, string $period): array
    {
        $to = now()->timestamp;
        $from = $to - self::PERIODS[$period];
        $itemId = $target->zabbix_host_id ? $this->itemId($target->zabbix_host_id, $metric, $series['key']) : null;
        $scale = (float) $series['scale'];

        [$source, $points, $stats] = match (true) {
            $itemId === null => ['none', [], self::stats([])],
            $period === '7d' => $this->fromTrends($itemId, $from, $scale) ?? $this->fromHistory($itemId, $from, $scale, $series['value_type']),
            default => $this->fromHistory($itemId, $from, $scale, $series['value_type']),
        };

        return [
            'metric' => $metric,
            'label' => $series['label'],
            'unit' => $series['unit'],
            'period' => $period,
            'from' => $from * 1000,
            'to' => $to * 1000,
            'source' => $source,
            'points' => $points,
            'stats' => $stats,
            'thresholds' => $target->provisioner()->chartThresholds($target, $metric),
        ];
    }

    /** Mesures brutes (history.get), regroupées si elles sont trop nombreuses. */
    private function fromHistory(string $itemId, int $from, float $scale, int $valueType): array
    {
        $rows = $this->zabbix->getHistory([
            'output' => ['clock', 'value'],
            'itemids' => [$itemId],
            'history' => $valueType,       // 0 flottant, 3 entier : type de valeur de l'item
            'time_from' => $from,
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
        ]);

        // Unité d'affichage : ex. secondes de Zabbix → millisecondes (scale 1000), octets → Mo
        $points = array_map(fn (array $row) => [(int) $row['clock'] * 1000, round((float) $row['value'] * $scale, 3)], $rows);

        return ['history', self::downsample($points), self::stats(array_column($points, 1))];
    }

    /**
     * Tendances horaires (trend.get) pour les longues périodes : min / moyenne / max déjà calculés par Zabbix.
     * Null si Zabbix n'en a pas encore (target récente) : on retombe alors sur l'historique.
     */
    private function fromTrends(string $itemId, int $from, float $scale): ?array
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
            array_map(fn (array $row) => [(int) $row['clock'] * 1000, round((float) $row['value_avg'] * $scale, 3)], $rows),
            [
                'avg' => $count > 0 ? round($weighted / $count * $scale, 1) : null,
                'min' => round(min(array_map('floatval', array_column($rows, 'value_min'))) * $scale, 1),
                'max' => round(max(array_map('floatval', array_column($rows, 'value_max'))) * $scale, 1),
                'count' => (int) $count,
            ],
        ];
    }

    /** Id de l'item d'une série ; il ne change pas, on le garde un jour. */
    private function itemId(string $hostId, string $metric, string $key): ?string
    {
        return Cache::remember("zabbix:item:$metric:$hostId", now()->addDay(), fn () => $this->zabbix->getItems([
            'output' => ['itemid'],
            'hostids' => [$hostId],
            'webitems' => true,            // items des web scenarios (API, site web) en plus des items classiques
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
