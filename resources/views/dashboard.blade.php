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
    {{-- KPIs (cahier des charges §20) --}}
    @php
        $kpis = [
            ['label' => 'Targets', 'value' => $total, 'icon' => 'bi-hdd-stack', 'color' => 'primary'],
            ['label' => 'UP', 'value' => $counts['up'], 'icon' => 'bi-check-circle', 'color' => 'success'],
            ['label' => 'Warning', 'value' => $counts['warning'], 'icon' => 'bi-exclamation-triangle', 'color' => 'warning'],
            ['label' => 'Critical', 'value' => $counts['critical'], 'icon' => 'bi-x-octagon', 'color' => 'danger'],
            ['label' => 'Unknown', 'value' => $counts['unknown'], 'icon' => 'bi-question-circle', 'color' => 'secondary'],
            ['label' => 'Active alerts', 'value' => 0, 'icon' => 'bi-bell', 'color' => 'danger'], // SCRUM-40
        ];
    @endphp

    <p class="section-label mb-2">Infrastructure overview</p>
    <div class="row g-3 mb-4">
        @foreach ($kpis as $kpi)
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card kpi-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="kpi-label">{{ $kpi['label'] }}</span>
                            <i class="bi {{ $kpi['icon'] }} text-{{ $kpi['color'] }}"></i>
                        </div>
                        <div class="kpi-value">{{ $kpi['value'] }}</div>
                        <div class="kpi-bar mt-3">
                            <span class="bg-{{ $kpi['color'] }}" style="width: {{ $total > 0 ? round($kpi['value'] / $total * 100) : 0 }}%"></span>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center py-3">
            <span class="fw-semibold">Monitoring targets</span>
            <span class="badge text-bg-secondary ms-2">{{ $total }}</span>
        </div>

        @if ($recentTargets->isEmpty())
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
                            <th>URL</th>
                            <th class="text-end pe-3">Thresholds</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentTargets as $target)
                            <tr>
                                <td class="ps-3"><a href="{{ route('targets.show', $target) }}" class="fw-semibold text-body text-decoration-none">{{ $target->name }}</a></td>
                                <td><x-status-pill :status="$target->status" /></td>
                                <td class="text-uppercase small text-body-secondary">{{ $target->type->value }}</td>
                                <td class="font-monospace-sm text-body-secondary text-truncate" style="max-width: 320px">{{ $target->url }}</td>
                                <td class="text-end pe-3 small">
                                    <span class="text-warning">{{ $target->warning_threshold_ms }}</span> /
                                    <span class="text-danger">{{ $target->critical_threshold_ms }}</span> ms
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
