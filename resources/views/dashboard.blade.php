@extends('layouts.app')

@section('title', 'Dashboard')

@section('actions')
    @if ($canCreate)
        <a href="{{ route('targets.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Add Monitoring Target
        </a>
    @endif
@endsection

@section('content')
    {{-- KPIs (cahier des charges §20, SCRUM-43) : chaque carte ouvre la liste correspondante --}}
    @php
        // Catégorie choisie (SCRUM-72) : gardée dans les liens vers la liste des targets
        $filter = $type ? ['type' => $type->value] : [];
        $kpis = [
            ['label' => 'Targets', 'value' => $total, 'icon' => 'bi-hdd-stack', 'color' => 'primary', 'url' => route('targets.index', $filter)],
            ['label' => 'UP', 'value' => $counts['up'], 'icon' => 'bi-check-circle', 'color' => 'success', 'url' => route('targets.index', ['status' => 'up', ...$filter])],
            ['label' => 'Warning', 'value' => $counts['warning'], 'icon' => 'bi-exclamation-triangle', 'color' => 'warning', 'url' => route('targets.index', ['status' => 'warning', ...$filter])],
            ['label' => 'Critical', 'value' => $counts['critical'], 'icon' => 'bi-x-octagon', 'color' => 'danger', 'url' => route('targets.index', ['status' => 'critical', ...$filter])],
            ['label' => 'Unknown', 'value' => $counts['unknown'], 'icon' => 'bi-question-circle', 'color' => 'secondary', 'url' => route('targets.index', ['status' => 'unknown', ...$filter])],
            ['label' => 'Active alerts', 'value' => $activeAlerts, 'icon' => 'bi-bell', 'color' => 'danger', 'url' => route('alerts.index')],
        ];
        $columns = \App\Support\TargetColumns::for($type);
    @endphp

    {{-- Catégories (cahier des charges §21, SCRUM-72) : All, Websites, APIs, Servers, Containers, Databases --}}
    <nav class="mb-3" aria-label="Category">
        <ul class="nav nav-pills gap-1 flex-wrap small">
            <li class="nav-item">
                <a href="{{ route('dashboard') }}" class="nav-link py-1 px-3 {{ $type ? '' : 'active' }}" @unless ($type) aria-current="page" @endunless>
                    All <span class="badge text-bg-secondary ms-1">{{ $allTargets }}</span>
                </a>
            </li>
            @foreach ($categories as $value => $count)
                @php
                    $category = \App\Enums\TargetType::from($value);
                @endphp
                <li class="nav-item">
                    <a href="{{ route('dashboard', ['type' => $value]) }}" class="nav-link py-1 px-3 {{ $type === $category ? 'active' : '' }}" @if ($type === $category) aria-current="page" @endif>
                        <i class="bi {{ $category->icon() }} me-1"></i>{{ $category->pluralLabel() }} <span class="badge text-bg-secondary ms-1">{{ $count }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <p class="section-label mb-2">{{ $type ? $type->pluralLabel() : 'Infrastructure' }} overview</p>
    <div class="row g-3 mb-4">
        @foreach ($kpis as $kpi)
            <div class="col-6 col-md-4 col-xl-2">
                <a href="{{ $kpi['url'] }}" class="card kpi-card h-100 text-decoration-none">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="kpi-label">{{ $kpi['label'] }}</span>
                            <i class="bi {{ $kpi['icon'] }} text-{{ $kpi['color'] }}"></i>
                        </div>
                        <div class="kpi-value {{ $kpi['label'] === 'Active alerts' && $kpi['value'] > 0 ? 'text-danger' : '' }}">{{ $kpi['value'] }}</div>
                        @if ($kpi['label'] !== 'Active alerts')
                            {{-- Part des targets dans ce statut --}}
                            <div class="kpi-bar mt-3">
                                <span class="bg-{{ $kpi['color'] }}" style="width: {{ $total > 0 ? round($kpi['value'] / $total * 100) : 0 }}%"></span>
                            </div>
                        @endif
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    {{-- 7 derniers jours, précalculés chaque heure (SCRUM-51) --}}
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-4 py-3">
            <span class="section-label mb-0">Last 7 days</span>
            <div>
                <span class="kpi-label d-block">Availability</span>
                <span class="fs-5 fw-semibold {{ \App\Support\StatFormat::availabilityColor($summary['availability']) }}" data-summary="availability">{{ $summary['availability'] === null ? '—' : \App\Support\StatFormat::percent($summary['availability']).' %' }}</span>
            </div>
            <div>
                <span class="kpi-label d-block">Incidents</span>
                <span class="fs-5 fw-semibold" data-summary="incidents">{{ $summary['computed_at'] ? number_format($summary['incidents']) : '—' }}</span>
            </div>
            <div>
                <span class="kpi-label d-block">Mean time to resolve</span>
                <span class="fs-5 fw-semibold" data-summary="mttr">{{ \App\Support\StatFormat::duration($summary['mttr_seconds']) ?? '—' }}</span>
            </div>
            <div>
                <span class="kpi-label d-block">Average response</span>
                <span class="fs-5 fw-semibold" data-summary="response">{{ $summary['avg_response_ms'] === null ? '—' : number_format($summary['avg_response_ms']).' ms' }}</span>
            </div>
            <span class="small text-body-secondary ms-lg-auto">
                {{ $summary['computed_at'] ? 'Updated '.$summary['computed_at']->toDisplay()->format('d/m H:i') : 'Statistics are computed every hour.' }}
            </span>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex align-items-center py-3">
            <span class="fw-semibold">Monitoring targets</span>
            <span class="badge text-bg-secondary ms-2">{{ $total }}</span>
            @if ($total > 0)
                <a href="{{ route('targets.index', $filter) }}" class="ms-auto small">View all<i class="bi bi-arrow-right ms-1"></i></a>
            @endif
        </div>

        @if ($targets->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-hdd-network text-body-secondary" style="font-size: 2.5rem"></i>
                @if ($type)
                    <p class="mt-3 mb-1 fw-semibold">No {{ strtolower($type->label()) }} monitored yet</p>
                    <a href="{{ route('dashboard') }}" class="small d-inline-block mb-3">Show all categories</a>
                @else
                    <p class="mt-3 mb-1 fw-semibold">No target monitored yet</p>
                    <p class="text-body-secondary small mb-3">Add your first target and Zabbix will start checking it automatically.</p>
                @endif
                @if ($canCreate)
                    <a href="{{ route('targets.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Monitoring Target</a>
                @endif
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Status</th>
                            <th>Type</th>
                            @foreach ($columns as $label)
                                <th class="text-end {{ $loop->last ? 'pe-3' : '' }}">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($targets as $target)
                            <tr>
                                <td class="ps-3">
                                    <a href="{{ route('targets.show', $target) }}" class="fw-semibold text-body text-decoration-none">{{ $target->name }}</a>
                                    <div class="font-monospace-sm text-body-secondary text-truncate" style="max-width: 320px">{{ $target->displayAddress() }}</div>
                                </td>
                                <td><x-status-pill :status="$target->status" /></td>
                                <td class="small text-body-secondary text-nowrap"><i class="bi {{ $target->type->icon() }} me-1"></i>{{ $target->type->label() }}</td>
                                @foreach ($columns as $column => $label)
                                    @php
                                        $cell = \App\Support\TargetColumns::cell($target, $column);
                                    @endphp
                                    <td class="text-end small text-nowrap {{ $loop->last ? 'pe-3' : '' }}"><span class="{{ $cell['class'] }}">{{ $cell['text'] }}</span></td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Dernières alertes (SCRUM-43) --}}
    <div class="card">
        <div class="card-header d-flex align-items-center py-3">
            <span class="fw-semibold">Latest alerts</span>
            @if ($activeAlerts > 0)
                <span class="badge text-bg-danger ms-2">{{ $activeAlerts }} active</span>
            @endif
            <a href="{{ route('alerts.history') }}" class="ms-auto small">History<i class="bi bi-arrow-right ms-1"></i></a>
        </div>
        @if ($recentAlerts->isEmpty())
            <div class="card-body text-center py-4">
                <i class="bi bi-check-circle text-success fs-3"></i>
                <p class="mb-0 mt-2 small text-body-secondary">No alert yet. Zabbix problems appear here within a minute.</p>
            </div>
        @else
            @include('alerts._table', ['alerts' => $recentAlerts, 'showTarget' => true])
        @endif
    </div>
@endsection
