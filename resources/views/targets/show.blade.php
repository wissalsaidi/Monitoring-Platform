@extends('layouts.app')

@section('title', $target->name)

@section('content')
    {{-- En-tête de la target, façon « frontend » de la maquette --}}
    <div class="card mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
                <span class="d-grid rounded-3 p-2 text-primary" style="background: rgba(91, 141, 239, .15)"><i class="bi bi-braces fs-4"></i></span>
                <div>
                    <h2 class="h4 mb-0">{{ $target->name }}</h2>
                    <span class="font-monospace-sm text-body-secondary">{{ $target->url }}</span>
                </div>
                <x-status-pill :status="$target->status" class="ms-md-3" />
            </div>
            <div class="d-flex flex-wrap gap-4 small text-body-secondary">
                <span><i class="bi bi-people me-1"></i>Team: {{ $target->team->name }}</span>
                <span><i class="bi bi-clock me-1"></i>Every {{ $intervals[$target->check_interval] ?? $target->check_interval.' s' }}</span>
                <span><i class="bi bi-calendar3 me-1"></i>Created {{ $target->created_at->diffForHumans() }}{{ $target->creator ? ' by '.$target->creator->name : '' }}</span>
            </div>

            {{-- Onglets du cahier des charges §14 : Overview seul disponible pour l'instant --}}
            <ul class="nav nav-underline mt-4 small">
                <li class="nav-item"><a class="nav-link active" aria-current="page" href="#">Overview</a></li>
                @foreach (['Metrics', 'History', 'Alerts', 'Configuration'] as $tab)
                    <li class="nav-item"><span class="nav-link disabled" title="Coming soon">{{ $tab }}</span></li>
                @endforeach
            </ul>
        </div>
    </div>

    <p class="section-label mb-2">Thresholds</p>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">Warning above</span>
                <div class="kpi-value text-warning">{{ $target->warning_threshold_ms }}<small class="fs-6 text-body-secondary ms-1">ms</small></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">Critical above</span>
                <div class="kpi-value text-danger">{{ $target->critical_threshold_ms }}<small class="fs-6 text-body-secondary ms-1">ms</small></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">Last response time</span>
                <div class="kpi-value text-body-secondary">—</div>
                <span class="small text-body-secondary">Live metrics arrive with the Zabbix sync.</span>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <p class="section-label mb-3">Zabbix</p>
            <dl class="row small mb-0">
                <dt class="col-sm-3 fw-normal text-body-secondary">Host</dt>
                <dd class="col-sm-9 font-monospace-sm">{{ $target->zabbixHostName() }} <span class="text-body-secondary">(id {{ $target->zabbix_host_id ?? '—' }})</span></dd>
                <dt class="col-sm-3 fw-normal text-body-secondary">Host group</dt>
                <dd class="col-sm-9 font-monospace-sm">{{ $target->team->zabbixHostGroupName() }}</dd>
                <dt class="col-sm-3 fw-normal text-body-secondary">Check</dt>
                <dd class="col-sm-9">Web scenario <code>{{ \App\Services\MonitoringService::SCENARIO }}</code>, 2xx expected</dd>
                @if ($target->description)
                    <dt class="col-sm-3 fw-normal text-body-secondary">Description</dt>
                    <dd class="col-sm-9 mb-0">{{ $target->description }}</dd>
                @endif
            </dl>
        </div>
    </div>
@endsection
