<?php

namespace App\Http\Controllers;

use App\Enums\TargetType;
use App\Exceptions\Zabbix\ZabbixException;
use App\Http\Requests\StoreApiTargetRequest;
use App\Models\MonitoringTarget;
use App\Services\MonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MonitoringTargetController extends Controller
{
    public const PER_PAGE = 15;

    /** Liste des targets des teams de l'utilisateur, filtrable par type et par nom (SCRUM-34). */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(TargetType::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $targets = MonitoringTarget::visibleTo($request->user())
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->whereLike('name', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('targets.index', [
            'targets' => $targets,
            'filters' => $filters,
            'types' => TargetType::cases(),
            'canCreate' => ($team = $request->user()->defaultTeam()) && $request->user()->can('create', [MonitoringTarget::class, $team]),
        ]);
    }

    public function create(Request $request): View
    {
        $team = $request->user()->defaultTeam();
        abort_if($team === null, 403);
        Gate::authorize('create', [MonitoringTarget::class, $team]);

        return view('targets.create', [
            'team' => $team,
            'intervals' => MonitoringTarget::CHECK_INTERVALS,
        ]);
    }

    public function store(StoreApiTargetRequest $request, MonitoringService $monitoring): RedirectResponse
    {
        try {
            $target = $monitoring->createApiTarget(
                $request->team(),
                $request->user(),
                $request->safe()->except('type'),
            );
        } catch (ZabbixException $e) {
            // Le détail technique est dans storage/logs/zabbix-*.log ; l'utilisateur voit un message propre (§47).
            return back()->withInput()->with('error', $e->userMessage().' The target was not created, please try again.');
        }

        return to_route('targets.show', $target)
            ->with('success', "Monitoring started for {$target->name}. First results within a minute.");
    }

    public function show(MonitoringTarget $target): View
    {
        Gate::authorize('view', $target);

        return view('targets.show', [
            'target' => $target->load('team', 'creator'),
            'intervals' => MonitoringTarget::CHECK_INTERVALS,
        ]);
    }
}
