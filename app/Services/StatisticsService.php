<?php

namespace App\Services;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\TargetDailyStat;
use App\Support\MetricRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Statistiques journalières des targets (SCRUM-51), calculées chaque heure (php artisan stats:compute) et lues par
 * l'onglet History et le dashboard sans appel à Zabbix.
 *
 * Disponibilité d'une journée : la plus basse de deux mesures, chacune réelle :
 * - la part des checks réussis (série « availability », SCRUM-49) ;
 * - la part du temps hors incident « indisponible » (alertes de métrique availability). Un agent injoignable ne
 *   renvoie aucune mesure : sans ce second calcul, une panne de serveur ou de conteneur ne baisserait pas la
 *   disponibilité (limite constatée en SCRUM-50).
 * Sans check ni incident : null (« — »), rien d'inventé.
 */
class StatisticsService
{
    /** Métrique (tag "metric") des incidents « indisponible » de tous les types */
    private const UNAVAILABLE_METRIC = 'availability';

    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * Calcule et enregistre la journée (dans le fuseau d'affichage) : crée la ligne ou remplace la précédente.
     * Rien pour une journée avant la création de la target, ou pas encore commencée.
     *
     * @throws ZabbixException
     */
    public function computeDay(MonitoringTarget $target, CarbonImmutable $day): ?TargetDailyStat
    {
        $timezone = config('app.display_timezone');
        $dayStart = $day->setTimezone($timezone)->startOfDay();
        $dayEnd = $dayStart->addDay();
        $from = max($dayStart->timestamp, $target->created_at->timestamp);
        $to = min($dayEnd->timestamp, now()->timestamp);

        if ($to <= $from) {
            return null;
        }

        $checks = $this->windowStats($target, 'availability', $from, $to);
        $response = array_key_exists('response_time', $target->provisioner()->chartSeries())
            ? $this->windowStats($target, 'response_time', $from, $to)
            : ['count' => 0, 'avg' => null];
        $downtime = $this->downtime($target, $from, $to);

        $availability = match (true) {
            $checks['count'] === 0 && $downtime === 0 => null,
            default => min(
                $checks['count'] > 0 ? $checks['avg'] : 100.0,
                round((1 - $downtime / ($to - $from)) * 100, 3),
            ),
        };

        // Bornes en UTC, comme les dates enregistrées (une date à l'heure de Paris serait comparée telle quelle)
        $bounds = [$dayStart->utc(), $dayEnd->subSecond()->utc()];
        $started = $target->alerts()->whereBetween('started_at', $bounds)->count();
        $resolved = $target->alerts()->whereBetween('resolved_at', $bounds)->get(['started_at', 'resolved_at']);

        return TargetDailyStat::updateOrCreate(
            ['monitoring_target_id' => $target->id, 'date' => $dayStart->toDateString()],
            [
                'covered_seconds' => $to - $from,
                'checks' => $checks['count'],
                'availability' => $availability,
                'downtime_seconds' => $downtime,
                'avg_response_ms' => $response['count'] > 0 ? $response['avg'] : null,
                'response_samples' => $response['count'],
                'incidents' => $started,
                'resolved_incidents' => $resolved->count(),
                'mttr_seconds' => $resolved->isEmpty() ? null
                    : (int) round($resolved->avg(fn (Alert $alert) => $alert->resolved_at->timestamp - $alert->started_at->timestamp)),
                'computed_at' => now(),
            ],
        );
    }

    /**
     * Chiffres d'une ou plusieurs targets sur les $days derniers jours (aujourd'hui compris), depuis la table :
     * disponibilité pondérée par la durée de chaque journée, temps de réponse moyen pondéré par le nombre de mesures,
     * incidents, MTTR pondéré par le nombre d'incidents résolus. null quand il n'y a rien à moyenner.
     *
     * @param  int|list<int>|Builder  $targetIds
     * @return array{availability: ?float, avg_response_ms: ?float, incidents: int, mttr_seconds: ?int, computed_at: ?CarbonImmutable, days: int}
     */
    public function summary(mixed $targetIds, int $days): array
    {
        $since = now()->setTimezone(config('app.display_timezone'))->subDays($days - 1)->toDateString();
        $rows = TargetDailyStat::whereIn('monitoring_target_id', is_int($targetIds) ? [$targetIds] : $targetIds)
            ->where('date', '>=', $since)
            ->get();

        return self::aggregate($rows, $days);
    }

    /** @param Collection<int, TargetDailyStat> $rows */
    public static function aggregate(Collection $rows, int $days): array
    {
        $measured = $rows->whereNotNull('availability');
        $withResponse = $rows->where('response_samples', '>', 0);
        $withResolved = $rows->where('resolved_incidents', '>', 0);
        $weighted = fn (Collection $items, string $value, string $weight) => $items->sum($weight) > 0
            ? $items->sum(fn ($row) => $row->{$value} * $row->{$weight}) / $items->sum($weight)
            : null;

        $availability = $weighted($measured, 'availability', 'covered_seconds');
        $response = $weighted($withResponse, 'avg_response_ms', 'response_samples');
        $mttr = $weighted($withResolved, 'mttr_seconds', 'resolved_incidents');
        $computedAt = $rows->max('computed_at');

        return [
            'availability' => $availability !== null ? round($availability, 3) : null,
            'avg_response_ms' => $response !== null ? round($response, 1) : null,
            'incidents' => (int) $rows->sum('incidents'),
            'mttr_seconds' => $mttr !== null ? (int) round($mttr) : null,
            'computed_at' => $computedAt ? CarbonImmutable::parse($computedAt) : null,
            'days' => $days,
        ];
    }

    /** Statistiques d'une série sur la fenêtre (historique Zabbix) */
    private function windowStats(MonitoringTarget $target, string $metric, int $from, int $to): array
    {
        if ($target->zabbix_host_id === null) {
            return ['count' => 0, 'avg' => null];
        }

        return $this->metrics->series($target, $metric, MetricRange::window($from, $to))['stats'];
    }

    /** Durée (s) couverte par les incidents « indisponible » dans la fenêtre ; incidents qui se chevauchent comptés une fois */
    private function downtime(MonitoringTarget $target, int $from, int $to): int
    {
        $intervals = $target->alerts()
            ->where('metric', self::UNAVAILABLE_METRIC)
            ->where('started_at', '<', CarbonImmutable::createFromTimestamp($to))
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>', CarbonImmutable::createFromTimestamp($from)))
            ->orderBy('started_at')
            ->get(['started_at', 'resolved_at'])
            ->map(fn (Alert $alert) => [
                max($from, $alert->started_at->timestamp),
                min($to, ($alert->resolved_at ?? now())->timestamp),
            ]);

        $total = 0;
        $cursor = $from;
        foreach ($intervals as [$start, $end]) {
            $start = max($start, $cursor);
            if ($end > $start) {
                $total += $end - $start;
                $cursor = $end;
            }
        }

        return $total;
    }
}
