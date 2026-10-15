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
        $kpis = [
            ['label' => 'Targets', 'value' => $total, 'icon' => 'bi-hdd-stack', 'color' => 'primary', 'url' => route('targets.index')],
            ['label' => 'UP', 'value' => $counts['up'], 'icon' => 'bi-check-circle', 'color' => 'success', 'url' => route('targets.index', ['status' => 'up'])],
            ['label' => 'Warning', 'value' => $counts['warning'], 'icon' => 'bi-exclamation-triangle', 'color' => 'warning', 'url' => route('targets.index', ['status' => 'warning'])],
            ['label' => 'Critical', 'value' => $counts['critical'], 'icon' => 'bi-x-octagon', 'color' => 'danger', 'url' => route('targets.index', ['status' => 'critical'])],
            ['label' => 'Unknown', 'value' => $counts['unknown'], 'icon' => 'bi-question-circle', 'color' => 'secondary', 'url' => route('targets.index', ['status' => 'unknown'])],
            ['label' => 'Active alerts', 'value' => $activeAlerts, 'icon' => 'bi-bell', 'color' => 'danger', 'url' => route('alerts.index')],
        ];
    @endphp

    <p class="section-label mb-2">Infrastructure overview</p>
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

    <div class="card mb-4">
        <div class="card-header d-flex align-items-center py-3">
            <span class="fw-semibold">Monitoring targets</span>
            <span class="badge text-bg-secondary ms-2">{{ $total }}</span>
            @if ($total > 0)
                <a href="{{ route('targets.index') }}" class="ms-auto small">View all<i class="bi bi-arrow-right ms-1"></i></a>
            @endif
        </div>

        @if ($targets->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-hdd-network text-body-secondary" style="font-size: 2.5rem"></i>
                <p class="mt-3 mb-1 fw-semibold">No target monitored yet</p>
                <p class="text-body-secondary small mb-3">Add your first API and Zabbix will start checking it automatically.</p>
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
                            <th class="text-end">Response time</th>
                            <th class="text-end pe-3">Thresholds</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($targets as $target)
                            <tr>
                                <td class="ps-3">
                                    <a href="{{ route('targets.show', $target) }}" class="fw-semibold text-body text-decoration-none">{{ $target->name }}</a>
                                    <div class="font-monospace-sm text-body-secondary text-truncate" style="max-width: 320px">{{ $target->url }}</div>
                                </td>
                                <td><x-status-pill :status="$target->status" /></td>
                                <td class="small text-body-secondary text-nowrap"><i class="bi {{ $target->type->icon() }} me-1"></i>{{ $target->type->label() }}</td>
                                <td class="text-end small text-nowrap">{{ $target->last_response_ms !== null ? number_format($target->last_response_ms).' ms' : '—' }}</td>
                                <td class="text-end pe-3 small text-nowrap">
                                    <span class="text-warning">{{ number_format($target->warning_threshold_ms) }}</span> /
                                    <span class="text-danger">{{ number_format($target->critical_threshold_ms) }}</span> ms
                                </td>
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
