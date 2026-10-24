<?php

namespace App\Services;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Support\MetricRange;
use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Séries de métriques lues dans Zabbix pour les graphiques (SCRUM-44, généralisé à tous les types en SCRUM-72).
 * Laravel ne stocke pas les mesures brutes (§38) : il les lit à la demande, avec un cache court.
 * Les séries d'un type sont déclarées par son provisioner (chartSeries) : clé d'item, unité, facteur.
 */
class MetricsService
{
    /** Périodes proposées (clé => durée en secondes) : onglet Metrics (SCRUM-49) */
    public const PERIODS = ['15m' => 900, '1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];

    public const PERIOD_LABELS = ['15m' => '15 minutes', '1h' => 'hour', '6h' => '6 hours', '24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'];

    /** Périodes du graphique de l'onglet Overview (SCRUM-44) */
    public const OVERVIEW_PERIODS = ['1h', '24h', '7d'];

    /** Disponibilité (SCRUM-49) : valeur de l'item quand la target répond (web.test.fail = 0, ping = 1) */
    public const UP_WHEN_ZERO = 'zero';

    public const UP_WHEN_ONE = 'one';

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
     * @param  string|MetricRange  $range  période prédéfinie (PERIODS) ou dates choisies (SCRUM-49)
     * @return array{metric: string, label: string, unit: string, period: string, period_label: string, from: int, to: int,
     *               source: string, points: list<array{0: int, 1: float}>,
     *               stats: array{last: ?float, avg: ?float, min: ?float, max: ?float, count: int},
     *               thresholds: array{warning: int|float|null, critical: int|float|null}}
     *
     * @throws ZabbixException
     * @throws InvalidArgumentException série inconnue pour ce type
     */
    public function series(MonitoringTarget $target, string $metric, string|MetricRange $range): array
    {
        $range = is_string($range) ? MetricRange::preset($range) : $range;
        $series = $target->provisioner()->chartSeries()[$metric]
            ?? throw new InvalidArgumentException("No \"$metric\" chart for a {$target->type->value} target.");

        return Cache::remember(
            "metrics:{$metric}:{$target->id}:{$range->cacheKey()}",
            self::CACHE_SECONDS,
            fn () => $this->fetch($target, $metric, $series, $range),
        );
    }

    private function fetch(MonitoringTarget $target, string $metric, array $series, MetricRange $range): array
    {
        $itemId = $target->zabbix_host_id ? $this->itemId($target->zabbix_host_id, $metric, $series['key']) : null;
        $value = self::converter($series);

        [$source, $points, $stats] = match (true) {
            $itemId === null => ['none', [], self::stats([])],
            $range->usesTrends() => $this->fromTrends($itemId, $range, $value) ?? $this->fromHistory($itemId, $range, $value, $series['value_type']),
            default => $this->fromHistory($itemId, $range, $value, $series['value_type']),
        };

        return [
            'metric' => $metric,
            'label' => $series['label'],
            'unit' => $series['unit'],
            'period' => $range->period,
            'period_label' => $range->label(),
            'from' => $range->from * 1000,
            'to' => $range->to * 1000,
            'source' => $source,
            'points' => $points,
            'stats' => $stats,
            'thresholds' => $target->provisioner()->chartThresholds($target, $metric),
        ];
    }

    /**
     * Valeur Zabbix → valeur affichée. Ex. secondes → millisecondes (scale 1000), octets → Mo.
     * Disponibilité : 100 % quand la target répond, 0 % sinon ; linéaire entre les deux, pour que la moyenne d'une
     * tendance horaire (ex. 0,25 échec en moyenne) devienne une disponibilité (75 %).
     *
     * @return Closure(float): float
     */
    private static function converter(array $series): Closure
    {
        $scale = (float) $series['scale'];

        return match ($series['availability'] ?? null) {
            self::UP_WHEN_ZERO => fn (float $raw) => round((1 - min(1, max(0, $raw))) * 100, 3),
            self::UP_WHEN_ONE => fn (float $raw) => round(min(1, max(0, $raw)) * 100, 3),
            default => fn (float $raw) => round($raw * $scale, 3),
        };
    }

    /** Mesures brutes (history.get), regroupées si elles sont trop nombreuses. */
    private function fromHistory(string $itemId, MetricRange $range, Closure $value, int $valueType): array
    {
        $rows = $this->zabbix->getHistory([
            'output' => ['clock', 'value'],
            'itemids' => [$itemId],
            'history' => $valueType,       // 0 flottant, 3 entier : type de valeur de l'item
            'time_from' => $range->from,
            'time_till' => $range->to,
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
        ]);

        $points = array_map(fn (array $row) => [(int) $row['clock'] * 1000, $value((float) $row['value'])], $rows);

        return ['history', self::downsample($points), self::stats(array_column($points, 1))];
    }

    /**
     * Tendances horaires (trend.get) pour les longues périodes : min / moyenne / max déjà calculés par Zabbix.
     * Null si Zabbix n'en a pas encore (target récente) : on retombe alors sur l'historique.
     */
    private function fromTrends(string $itemId, MetricRange $range, Closure $value): ?array
    {
        $rows = $this->zabbix->getTrends([
            'output' => ['clock', 'num', 'value_min', 'value_avg', 'value_max'],
            'itemids' => [$itemId],
            'time_from' => $range->from,
            'time_till' => $range->to,
        ]);

        if ($rows === []) {
            return null;
        }

        usort($rows, fn ($a, $b) => $a['clock'] <=> $b['clock']);
        $count = array_sum(array_column($rows, 'num'));
        $weighted = array_sum(array_map(fn ($row) => (float) $row['value_avg'] * (int) $row['num'], $rows));
        $points = array_map(fn (array $row) => [(int) $row['clock'] * 1000, $value((float) $row['value_avg'])], $rows);
        // Min et max convertis : pour la disponibilité ils s'inversent (le plus d'échecs donne la plus basse disponibilité)
        $extremes = array_merge(...array_map(fn (array $row) => [$value((float) $row['value_min']), $value((float) $row['value_max'])], $rows));

        return [
            'trends',
            self::downsample($points),
            [
                'last' => round(end($points)[1], 1),
                'avg' => $count > 0 ? round($value($weighted / $count), 1) : null,
                'min' => round(min($extremes), 1),
                'max' => round(max($extremes), 1),
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

    /** @param list<float> $values dans l'ordre chronologique */
    private static function stats(array $values): array
    {
        return [
            'last' => $values ? round(end($values), 1) : null,
            'avg' => $values ? round(array_sum($values) / count($values), 1) : null,
            'min' => $values ? round(min($values), 1) : null,
            'max' => $values ? round(max($values), 1) : null,
            'count' => count($values),
        ];
    }
}
