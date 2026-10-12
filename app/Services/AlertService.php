<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\TargetStatus;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alertes de la plateforme, construites à partir des problèmes Zabbix (cahier des charges §36, SCRUM-40).
 */
class AlertService
{
    public function __construct(private readonly ZabbixService $zabbix) {}

    /**
     * Synchronise les problèmes Zabbix des targets dans la table alerts, puis le statut des targets.
     * Idempotent : un problème Zabbix = une alerte (zabbix_event_id unique).
     *
     * @return array{problems: int, created: int, resolved: int, targets: int}
     *
     * @throws ZabbixException
     */
    public function syncFromZabbix(): array
    {
        $stats = ['problems' => 0, 'created' => 0, 'resolved' => 0, 'targets' => 0];

        /** @var Collection<int, MonitoringTarget> $targets */
        $targets = MonitoringTarget::whereNotNull('zabbix_host_id')->get()->keyBy('id');

        if ($targets->isEmpty()) {
            return $stats;
        }

        $hostIds = $targets->pluck('zabbix_host_id')->values()->all();

        // Lectures Zabbix d'abord : si Zabbix est injoignable, rien n'est modifié en base.
        $problems = $this->zabbix->getProblems([
            'output' => ['eventid', 'objectid', 'clock', 'r_eventid', 'r_clock', 'severity', 'name', 'acknowledged'],
            'hostids' => $hostIds,
            'recent' => true,              // inclut les problèmes résolus récemment (r_clock)
            'selectTags' => ['tag', 'value'],
            'sortfield' => ['eventid'],
        ]);
        $items = $this->checkItems($hostIds);
        $stats['problems'] = count($problems);

        $seen = [];

        foreach ($problems as $problem) {
            $target = $targets->get((int) self::tag($problem, 'target_id'));

            if ($target === null) {
                Log::channel('zabbix')->warning('Zabbix problem without a known target', ['eventid' => $problem['eventid']]);

                continue;
            }

            $seen[] = $problem['eventid'];
            $alert = $this->upsertAlert($target, $problem, $items);

            $stats['created'] += $alert->wasRecentlyCreated ? 1 : 0;
            $stats['resolved'] += $alert->wasChanged('status') && $alert->status === AlertStatus::Resolved ? 1 : 0;
        }

        $stats['resolved'] += $this->resolveVanishedAlerts($seen);
        $stats['targets'] = $this->updateTargetStatuses($targets, $items);

        return $stats;
    }

    /**
     * Crée ou met à jour l'alerte d'un problème Zabbix.
     *
     * @param  array<string, array<string, array>>  $items  items de check par host puis par clé
     */
    private function upsertAlert(MonitoringTarget $target, array $problem, array $items): Alert
    {
        $alert = Alert::firstOrNew(['zabbix_event_id' => $problem['eventid']]);
        $metric = self::tag($problem, 'metric');
        $resolved = $problem['r_eventid'] !== '0';

        if (! $alert->exists) {
            $alert->fill([
                'monitoring_target_id' => $target->id,
                'zabbix_trigger_id' => $problem['objectid'],
                'metric' => $metric,
                'threshold' => $this->threshold($target, self::tag($problem, 'level')),
                'value' => $metric === 'response_time'
                    ? $this->responseTimeAt($items[$target->zabbix_host_id][self::timeKey()]['itemid'] ?? null, (int) $problem['clock'])
                    : null,
                'started_at' => Carbon::createFromTimestamp((int) $problem['clock']),
            ]);
        }

        $alert->severity = AlertSeverity::fromZabbix($problem['severity']);
        $alert->message = $problem['name'];

        if ($resolved) {
            $alert->status = AlertStatus::Resolved;
            $alert->resolved_at = Carbon::createFromTimestamp((int) $problem['r_clock']);
        } elseif ($problem['acknowledged'] === '1' && $alert->status !== AlertStatus::Acknowledged) {
            // Acquittée directement dans Zabbix
            $alert->status = AlertStatus::Acknowledged;
            $alert->acknowledged_at = now();
        }

        $alert->save();

        return $alert;
    }

    /**
     * Alertes encore actives en base mais absentes de problem.get (résolues depuis trop longtemps pour
     * "recent", ou événement supprimé) : on lit leur événement de résolution.
     *
     * @param  string[]  $seen  ids des événements déjà traités
     * @return int nombre d'alertes résolues
     */
    private function resolveVanishedAlerts(array $seen): int
    {
        $alerts = Alert::active()->whereNotIn('zabbix_event_id', $seen)->get();

        if ($alerts->isEmpty()) {
            return 0;
        }

        $events = collect($this->zabbix->getEvents([
            'output' => ['eventid', 'r_eventid'],
            'eventids' => $alerts->pluck('zabbix_event_id')->all(),
        ]))->keyBy('eventid');

        $recoveryIds = $events->pluck('r_eventid')->reject(fn ($id) => $id === '0')->values()->all();
        $recoveries = $recoveryIds === [] ? collect() : collect($this->zabbix->getEvents([
            'output' => ['eventid', 'clock'],
            'eventids' => $recoveryIds,
        ]))->keyBy('eventid');

        $resolved = 0;

        foreach ($alerts as $alert) {
            $event = $events->get($alert->zabbix_event_id);

            if ($event !== null && $event['r_eventid'] === '0') {
                continue; // toujours en cours (problème masqué par une dépendance, par exemple)
            }

            $clock = $recoveries->get($event['r_eventid'] ?? '')['clock'] ?? null;
            $alert->update([
                'status' => AlertStatus::Resolved,
                'resolved_at' => $clock ? Carbon::createFromTimestamp((int) $clock) : now(),
            ]);
            $resolved++;
        }

        return $resolved;
    }

    /**
     * Statut de chaque target : pire alerte active, sinon UP si Zabbix a déjà fait un check, sinon UNKNOWN.
     * Recopie aussi l'heure et le temps de réponse du dernier check.
     *
     * @return int nombre de targets dont le statut a changé
     */
    private function updateTargetStatuses(Collection $targets, array $items): int
    {
        $worst = Alert::active()
            ->whereIn('monitoring_target_id', $targets->keys())
            ->get(['monitoring_target_id', 'severity'])
            ->groupBy('monitoring_target_id')
            ->map(fn (Collection $alerts) => $alerts->sortByDesc(fn (Alert $a) => $a->severity->rank())->first()->severity);

        $changed = 0;

        DB::transaction(function () use ($targets, $items, $worst, &$changed) {
            foreach ($targets as $target) {
                $lastClock = (int) ($items[$target->zabbix_host_id][self::failKey()]['lastclock'] ?? 0);
                $time = $items[$target->zabbix_host_id][self::timeKey()] ?? null;
                $status = $worst->get($target->id)?->targetStatus() ?? ($lastClock > 0 ? TargetStatus::Up : TargetStatus::Unknown);

                $changed += $target->status !== $status ? 1 : 0;
                $target->update([
                    'status' => $status,
                    'last_checked_at' => $lastClock > 0 ? Carbon::createFromTimestamp($lastClock) : null,
                    'last_response_ms' => ($time['lastclock'] ?? '0') !== '0' ? round((float) $time['lastvalue'] * 1000, 3) : null,
                ]);
            }
        });

        return $changed;
    }

    /**
     * Items du web scenario (échec et temps de réponse) des hosts, indexés par host puis par clé.
     *
     * @return array<string, array<string, array>>
     */
    private function checkItems(array $hostIds): array
    {
        $items = $this->zabbix->getItems([
            'output' => ['itemid', 'hostid', 'key_', 'lastclock', 'lastvalue'],
            'hostids' => $hostIds,
            'webitems' => true,
            'filter' => ['key_' => [self::failKey(), self::timeKey()]],
        ]);

        $byHost = [];
        foreach ($items as $item) {
            $byHost[$item['hostid']][$item['key_']] = $item;
        }

        return $byHost;
    }

    /** Temps de réponse (ms) mesuré au moment du problème. Facultatif : null si indisponible. */
    private function responseTimeAt(?string $itemId, int $clock): ?float
    {
        if ($itemId === null) {
            return null;
        }

        try {
            $history = $this->zabbix->getHistory([
                'output' => ['clock', 'value'],
                'itemids' => [$itemId],
                'history' => 0,                // valeurs numériques flottantes
                'time_till' => $clock,
                'sortfield' => 'clock',
                'sortorder' => 'DESC',
                'limit' => 1,
            ]);
        } catch (ZabbixException $e) {
            Log::channel('zabbix')->warning('Unable to read the response time of a problem', ['itemid' => $itemId, 'error' => $e->getMessage()]);

            return null;
        }

        return isset($history[0]['value']) ? round((float) $history[0]['value'] * 1000, 3) : null;
    }

    private function threshold(MonitoringTarget $target, ?string $level): ?int
    {
        return match ($level) {
            'warning' => $target->warning_threshold_ms,
            'critical' => $target->critical_threshold_ms,
            default => null,
        };
    }

    private static function tag(array $problem, string $name): ?string
    {
        foreach ($problem['tags'] ?? [] as $tag) {
            if ($tag['tag'] === $name) {
                return $tag['value'];
            }
        }

        return null;
    }

    private static function failKey(): string
    {
        return sprintf('web.test.fail[%s]', MonitoringService::SCENARIO);
    }

    private static function timeKey(): string
    {
        return sprintf('web.test.time[%s,%s,resp]', MonitoringService::SCENARIO, MonitoringService::STEP);
    }
}
