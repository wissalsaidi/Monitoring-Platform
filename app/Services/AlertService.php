<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\TargetStatus;
use App\Exceptions\Zabbix\ZabbixApiException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Models\User;
use App\Support\ZabbixSyncHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alertes de la plateforme, construites à partir des problèmes Zabbix (cahier des charges §36, SCRUM-40).
 */
class AlertService
{
    public function __construct(
        private readonly ZabbixService $zabbix,
        private readonly AlertNotifier $notifier,
    ) {}

    /**
     * Acquitte une alerte (SCRUM-42) : d'abord dans Zabbix, puis en base, avec une trace d'audit.
     * Si Zabbix refuse ou est injoignable, rien n'est modifié.
     *
     * @throws ZabbixException
     */
    public function acknowledge(Alert $alert, User $user): void
    {
        try {
            $this->zabbix->acknowledgeEvents([$alert->zabbix_event_id], "Acknowledged by {$user->name} from Monitoring Platform");
        } catch (ZabbixApiException $e) {
            // Déjà acquittée dans Zabbix (avant la synchro suivante) : l'acquittement local reste valable.
            if (! $this->isAcknowledgedInZabbix($alert)) {
                throw $e;
            }
        }

        $alert->update([
            'status' => AlertStatus::Acknowledged,
            'acknowledged_at' => now(),
            'acknowledged_by' => $user->id,
        ]);

        AuditLog::record('alert.acknowledge', $user, $alert, [
            'target' => $alert->target->name,
            'severity' => $alert->severity->value,
            'zabbix_event_id' => $alert->zabbix_event_id,
        ]);
    }

    private function isAcknowledgedInZabbix(Alert $alert): bool
    {
        $events = $this->zabbix->getEvents(['output' => ['eventid', 'acknowledged'], 'eventids' => [$alert->zabbix_event_id]]);

        return ($events[0]['acknowledged'] ?? '0') === '1';
    }

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
        try {
            $stats = $this->synchronize();
        } catch (ZabbixException $e) {
            ZabbixSyncHealth::recordFailure();

            throw $e;
        }

        ZabbixSyncHealth::recordSuccess();

        return $stats;
    }

    /**
     * @return array{problems: int, created: int, resolved: int, targets: int}
     *
     * @throws ZabbixException
     */
    private function synchronize(): array
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
        $items = $this->checkItems($targets);
        $stats['problems'] = count($problems);

        $seen = [];

        foreach ($problems as $problem) {
            $target = $targets->get((int) self::tag($problem, 'target_id'));

            if ($target === null) {
                Log::channel('zabbix')->warning('Zabbix problem without a known target', ['eventid' => $problem['eventid']]);

                continue;
            }

            // Seuls nos triggers (tag "level") deviennent des alertes. Ceux d'un template lié (Linux pour les serveurs,
            // SCRUM-69) restent visibles dans Zabbix : seuils fixes, doublons de nos alertes CPU / mémoire / disque.
            if (self::tag($problem, 'level') === null) {
                continue;
            }

            $seen[] = $problem['eventid'];
            $alert = $this->upsertAlert($target, $problem, $items);

            $stats['created'] += $alert->wasRecentlyCreated ? 1 : 0;

            // Nouvelle alerte : emails selon les règles de la team, par la queue (SCRUM-53)
            if ($alert->wasRecentlyCreated) {
                $this->notifier->alertOpened($alert);
            }
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
                'threshold' => $target->provisioner()->threshold($target, (string) self::tag($problem, 'level')),
                'value' => $metric === 'response_time'
                    ? $this->responseTimeAt($this->statusItem($items, $target, 'response_time')['itemid'] ?? null, (int) $problem['clock'])
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
                $lastClock = (int) ($this->statusItem($items, $target, 'availability')['lastclock'] ?? 0);
                $time = $this->statusItem($items, $target, 'response_time');
                $status = $worst->get($target->id)?->targetStatus() ?? ($lastClock > 0 ? TargetStatus::Up : TargetStatus::Unknown);

                $changed += $target->status !== $status ? 1 : 0;
                $target->update([
                    'status' => $status,
                    'last_checked_at' => $lastClock > 0 ? Carbon::createFromTimestamp($lastClock) : null,
                    'last_response_ms' => ($time['lastclock'] ?? '0') !== '0' ? round((float) $time['lastvalue'] * 1000, 3) : null,
                    'last_metrics' => $this->lastMetrics($items, $target),
                ]);
            }
        });

        return $changed;
    }

    /**
     * Items de statut des targets (disponibilité et temps de réponse, déclarés par le provisioner de chaque type),
     * en une seule requête, indexés par host puis par clé.
     *
     * @param  Collection<int, MonitoringTarget>  $targets
     * @return array<string, array<string, array>>
     */
    private function checkItems(Collection $targets): array
    {
        $keys = $targets->map(fn (MonitoringTarget $target) => [
            ...array_values(array_filter($target->provisioner()->statusItemKeys())),
            ...array_values($target->provisioner()->metricItemKeys()),
        ])->flatten()->unique()->values()->all();

        $items = $this->zabbix->getItems([
            'output' => ['itemid', 'hostid', 'key_', 'lastclock', 'lastvalue'],
            'hostids' => $targets->pluck('zabbix_host_id')->values()->all(),
            'webitems' => true,            // items des web scenarios (API, site web) en plus des items classiques
            'filter' => ['key_' => $keys],
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

    /**
     * Item de statut d'une target (availability ou response_time), ou null si le type n'en a pas.
     *
     * @param  array<string, array<string, array>>  $items
     */
    private function statusItem(array $items, MonitoringTarget $target, string $role): ?array
    {
        $key = $target->provisioner()->statusItemKeys()[$role] ?? null;

        return $key !== null ? ($items[$target->zabbix_host_id][$key] ?? null) : null;
    }

    /**
     * Dernières valeurs des métriques propres au type (code HTTP, jours avant l'expiration du certificat, OS…),
     * null pour une métrique pas encore mesurée. Les textes (OS) sont gardés tels quels, coupés à 255 caractères.
     *
     * @param  array<string, array<string, array>>  $items
     * @return array<string, int|float|string|null>|null
     */
    private function lastMetrics(array $items, MonitoringTarget $target): ?array
    {
        $keys = $target->provisioner()->metricItemKeys();

        if ($keys === []) {
            return null;
        }

        return array_map(function (string $key) use ($items, $target) {
            $item = $items[$target->zabbix_host_id][$key] ?? null;

            if (($item['lastclock'] ?? '0') === '0') {
                return null;
            }

            return is_numeric($item['lastvalue']) ? $item['lastvalue'] + 0 : mb_substr((string) $item['lastvalue'], 0, 255);
        }, $keys);
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
}
