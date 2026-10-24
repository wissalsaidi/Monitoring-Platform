<?php

namespace App\Http\Controllers;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Exceptions\Zabbix\ZabbixException;
use App\Http\Requests\StoreTargetRequest;
use App\Http\Requests\UpdateTargetRequest;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Monitoring\ProvisionerRegistry;
use App\Services\IncidentHistoryService;
use App\Services\MonitoringService;
use App\Services\StatisticsService;
use App\Support\MetricRange;
use App\Support\ZabbixSyncHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MonitoringTargetController extends Controller
{
    public const PER_PAGE = 15;

    /** Onglets de la page d'une target (§14). Metrics et History arrivent en V1. */
    public const TABS = ['overview', 'metrics', 'history', 'alerts', 'configuration'];

    /** Onglet History (SCRUM-50) : incidents par page, et au plus sur la frise */
    public const INCIDENTS_PER_PAGE = 20;

    public const TIMELINE_MAX = 300;

    /** Statistiques de l'onglet History (SCRUM-51) : aujourd'hui, 7 et 30 derniers jours */
    public const SUMMARY_DAYS = [1, 7, 30];

    /** Liste des targets des teams de l'utilisateur, filtrable par type et par nom (SCRUM-34). */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(TargetType::class)],
            'status' => ['nullable', Rule::enum(TargetStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $targets = MonitoringTarget::visibleTo($request->user())
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->whereLike('name', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('targets.index', [
            'targets' => $targets,
            'filters' => $filters,
            'types' => TargetType::cases(),
            'statuses' => TargetStatus::cases(),
            'canCreate' => ($team = $request->user()->defaultTeam()) && $request->user()->can('create', [MonitoringTarget::class, $team]),
        ]);
    }

    public function create(Request $request, ProvisionerRegistry $provisioners): View
    {
        $team = $request->user()->defaultTeam();
        abort_if($team === null, 403);
        Gate::authorize('create', [MonitoringTarget::class, $team]);

        return view('targets.form', [
            'team' => $team,
            'target' => null,
            'intervals' => MonitoringTarget::CHECK_INTERVALS,
            'supportedTypes' => $provisioners->supportedTypes(),
        ]);
    }

    public function store(StoreTargetRequest $request, MonitoringService $monitoring): RedirectResponse
    {
        try {
            $target = $monitoring->createTarget(
                $request->team(),
                $request->user(),
                $request->targetType(),
                $request->targetData(),
            );
        } catch (ZabbixException $e) {
            // Le détail technique est dans storage/logs/zabbix-*.log ; l'utilisateur voit un message propre (§47).
            // Jamais de secret dans la session (mot de passe d'une base, SCRUM-71)
            return back()->withInput($request->except(MonitoringTarget::SECRET_ATTRIBUTES))->with('error', $e->userMessage().' The target was not created, please try again.');
        }

        AuditLog::record('target.create', $request->user(), $target, ['name' => $target->name, 'url' => $target->url]);

        return to_route('targets.show', $target)
            ->with('success', "Monitoring started for {$target->name}. First results within a minute.");
    }

    /** Formulaire de modification (SCRUM-35) : Admin / Manager de la team */
    public function edit(MonitoringTarget $target, ProvisionerRegistry $provisioners): View
    {
        Gate::authorize('update', $target);

        return view('targets.form', [
            'team' => $target->team,
            'target' => $target,
            'intervals' => MonitoringTarget::CHECK_INTERVALS,
            'supportedTypes' => $provisioners->supportedTypes(),
        ]);
    }

    public function update(UpdateTargetRequest $request, MonitoringTarget $target, MonitoringService $monitoring): RedirectResponse
    {
        try {
            $changes = $monitoring->updateTarget($target, $request->targetData());
        } catch (ZabbixException $e) {
            return back()->withInput($request->except(MonitoringTarget::SECRET_ATTRIBUTES))->with('error', $e->userMessage().' The target was not modified, please try again.');
        }

        if ($changes === []) {
            return to_route('targets.show', $target)->with('success', 'No change to save.');
        }

        AuditLog::record('target.update', $request->user(), $target, ['changes' => $changes]);

        return to_route('targets.show', [$target, 'tab' => 'configuration'])
            ->with('success', "{$target->name} updated. Zabbix applies the new settings at the next check.");
    }

    /** Suppression (SCRUM-35) : host Zabbix, target et alertes */
    public function destroy(Request $request, MonitoringTarget $target, MonitoringService $monitoring): RedirectResponse
    {
        Gate::authorize('delete', $target);

        try {
            $monitoring->deleteTarget($target);
        } catch (ZabbixException $e) {
            return back()->with('error', $e->userMessage().' The target was not deleted, please try again.');
        }

        AuditLog::record('target.delete', $request->user(), $target, [
            'name' => $target->name,
            'url' => $target->url,
            'zabbix_host_id' => $target->zabbix_host_id,
        ]);

        return to_route('targets.index')->with('success', "{$target->name} deleted, with its Zabbix monitoring and its alerts.");
    }

    public function show(Request $request, MonitoringTarget $target): View
    {
        Gate::authorize('view', $target);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';
        $target->load('team', 'creator');

        $activeAlerts = $target->alerts()->active()->with('acknowledger:id,name')->orderByDesc('started_at')->get();
        $alerts = $tab === 'alerts'
            ? $target->alerts()->with('acknowledger:id,name')->orderByDesc('started_at')->orderByDesc('id')
                ->paginate(AlertController::PER_PAGE)->withQueryString()
            : null;

        // La policy d'acquittement lit $alert->target->team : on réutilise la target déjà chargée.
        $activeAlerts->each->setRelation('target', $target);
        $alerts?->getCollection()->each->setRelation('target', $target);

        // Onglets Metrics (SCRUM-49, 24 h par défaut) et History (SCRUM-50, 7 j) : période choisie ;
        // dates invalides → période par défaut, avec le message d'erreur
        $range = null;
        $rangeErrors = null;
        if (in_array($tab, ['metrics', 'history'], true)) {
            $default = $tab === 'metrics' ? '24h' : '7d';
            try {
                $range = MetricRange::fromRequest($request, $default);
            } catch (ValidationException $e) {
                $range = MetricRange::preset($default);
                $rangeErrors = $e->validator->errors();
            }
        }

        $history = $tab === 'history' ? $this->history($target, $range) : null;

        return view('targets.show', [
            'target' => $target,
            'tab' => $tab,
            'range' => $range,
            'rangeErrors' => $rangeErrors,
            'intervals' => MonitoringTarget::CHECK_INTERVALS,
            'activeAlerts' => $activeAlerts,
            'alertCount' => $target->alerts()->count(),
            'alerts' => $alerts,
            'zabbixFailing' => ZabbixSyncHealth::isFailing(),
            'history' => $history,
        ]);
    }

    /**
     * Onglet History (SCRUM-50) : incidents (alertes) qui touchent la période, frise et tableau paginé,
     * valeur maximale de chaque incident du tableau ; statistiques du jour, de 7 et de 30 jours (SCRUM-51).
     */
    private function history(MonitoringTarget $target, MetricRange $range): array
    {
        $from = Carbon::createFromTimestamp($range->from);
        $to = Carbon::createFromTimestamp($range->to);
        // Incident commencé avant la fin de la période et pas terminé avant son début
        $inRange = fn () => $target->alerts()
            ->where('started_at', '<=', $to)
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $from));

        $incidents = $inRange()->orderByDesc('started_at')->orderByDesc('id')
            ->paginate(self::INCIDENTS_PER_PAGE)->withQueryString();
        $service = app(IncidentHistoryService::class);
        $peaks = $service->peaks($target, $incidents->getCollection());
        // Chiffres précalculés chaque heure (SCRUM-51) : aucun appel à Zabbix
        $statistics = app(StatisticsService::class);
        $summaries = collect(self::SUMMARY_DAYS)->mapWithKeys(fn (int $days) => [$days => $statistics->summary($target->id, $days)]);

        return [
            'incidents' => $incidents,
            'timeline' => $inRange()->orderBy('started_at')->limit(self::TIMELINE_MAX)->get(['id', 'severity', 'metric', 'message', 'started_at', 'resolved_at']),
            'total' => $incidents->total(),
            'peaks' => $peaks,
            'summaries' => $summaries,
            'zabbixFailed' => $service->zabbixFailed,
        ];
    }
}
