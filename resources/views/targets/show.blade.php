@extends('layouts.app')

@section('title', $target->name)

@section('actions')
    @if (app(\App\Services\GrafanaService::class)->supports($target))
        {{-- Dashboard Grafana de la target (SCRUM-47) ; la période suit celle du graphique (metric-chart.js) --}}
        <a href="{{ route('targets.grafana', [$target, ...($range?->query() ?? ['period' => '1h'])]) }}" class="btn btn-outline-secondary btn-sm"
           target="_blank" rel="noopener" data-grafana-link>
            <i class="bi bi-box-arrow-up-right me-1"></i>Open Grafana dashboard
        </a>
    @endif
    @can('update', $target)
        <a href="{{ route('targets.edit', $target) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
    @endcan
    @can('delete', $target)
        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#delete-target">
            <i class="bi bi-trash me-1"></i>Delete
        </button>
    @endcan
@endsection

@section('content')
    {{-- En-tête de la target, façon « frontend » de la maquette --}}
    <div class="card mb-4">
        <div class="card-body p-4 pb-0">
            <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
                <span class="d-grid rounded-3 p-2 text-primary" style="background: rgba(91, 141, 239, .15)"><i class="bi {{ $target->type->icon() }} fs-4"></i></span>
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

            {{-- Onglets du cahier des charges §14 : Metrics (SCRUM-49), History (SCRUM-50) --}}
            @php
                $tabs = [
                    'overview' => 'Overview',
                    'metrics' => 'Metrics',
                    'history' => 'History',
                    'alerts' => 'Alerts',
                    'configuration' => 'Configuration',
                ];
            @endphp
            <ul class="nav nav-underline mt-4 small">
                @foreach ($tabs as $key => $label)
                    <li class="nav-item">
                        <a class="nav-link {{ $tab === $key ? 'active' : '' }}" @if ($tab === $key) aria-current="page" @endif
                           href="{{ route('targets.show', $key === 'overview' ? $target : [$target, 'tab' => $key]) }}">
                            {{ $label }}
                            @if ($key === 'alerts' && $alertCount > 0)
                                <span class="badge {{ $activeAlerts->isNotEmpty() ? 'text-bg-danger' : 'text-bg-secondary' }} ms-1">{{ $alertCount }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    @if ($tab === 'overview')
        @if (! $zabbixFailing && $target->isStale())
            {{-- Pas de nouvelle mesure depuis longtemps : message générique (§47), pas de détail technique --}}
            <div class="alert alert-warning d-flex align-items-center gap-2" role="status">
                <i class="bi bi-hourglass-split"></i>
                <span>No recent data from the monitoring service. The last check was {{ $target->last_checked_at->diffForHumans() }}.</span>
            </div>
        @endif

        @php
            // Types avec un temps de réponse (API, site web) : cartes en ms et graphique ; serveur : ressources (SCRUM-69)
            $hasResponseTime = ($target->provisioner()->statusItemKeys()['response_time'] ?? null) !== null;
            $isServer = $target->type === \App\Enums\TargetType::Server;
            $isContainer = $target->type === \App\Enums\TargetType::Container;
            $isDatabase = $target->type === \App\Enums\TargetType::Database;
        @endphp
        <p class="section-label mb-2">Current state</p>
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="card kpi-card h-100"><div class="card-body">
                    <span class="kpi-label">Status</span>
                    <div class="my-2"><x-status-pill :status="$target->status" /></div>
                    <span class="small text-body-secondary">
                        @if ($target->last_checked_at)
                            Checked <time datetime="{{ $target->last_checked_at->toIso8601String() }}" title="{{ $target->last_checked_at->toDisplay()->format('d/m/Y H:i:s') }}">{{ $target->last_checked_at->diffForHumans() }}</time>
                        @else
                            Waiting for the first check
                        @endif
                    </span>
                </div></div>
            </div>
            @if ($hasResponseTime)
            <div class="col-6 col-lg-3">
                <div class="card kpi-card h-100"><div class="card-body">
                    <span class="kpi-label">Last response time</span>
                    @if ($target->last_response_ms !== null)
                        @php
                            // Base de données (SCRUM-71) : temps de réponse sans seuils en ms
                            $color = match (true) {
                                $target->critical_threshold_ms === null => '',
                                $target->last_response_ms > $target->critical_threshold_ms => 'text-danger',
                                $target->last_response_ms > $target->warning_threshold_ms => 'text-warning',
                                default => 'text-success',
                            };
                        @endphp
                        <div class="kpi-value {{ $color }}">{{ number_format($target->last_response_ms) }}<small class="fs-6 text-body-secondary ms-1">ms</small></div>
                    @else
                        <div class="kpi-value text-body-secondary">—</div>
                        <span class="small text-body-secondary">No measure yet</span>
                    @endif
                </div></div>
            </div>
            @endif
            @if ($hasResponseTime && $target->warning_threshold_ms !== null)
            <div class="col-6 col-lg-3">
                <div class="card kpi-card h-100"><div class="card-body">
                    <span class="kpi-label">Warning above</span>
                    <div class="kpi-value text-warning">{{ number_format($target->warning_threshold_ms) }}<small class="fs-6 text-body-secondary ms-1">ms</small></div>
                </div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card kpi-card h-100"><div class="card-body">
                    <span class="kpi-label">Critical above</span>
                    <div class="kpi-value text-danger">{{ number_format($target->critical_threshold_ms) }}<small class="fs-6 text-body-secondary ms-1">ms</small></div>
                </div></div>
            </div>
            @elseif ($isServer)
                @include('targets._server-overview', ['part' => 'identity'])
            @elseif ($isContainer)
                @include('targets._container-overview', ['part' => 'identity'])
            @elseif ($isDatabase)
                @include('targets._database-overview', ['part' => 'identity'])
            @endif
        </div>

        @if ($isServer)
            @include('targets._server-overview', ['part' => 'resources'])
        @elseif ($isContainer)
            @include('targets._container-overview', ['part' => 'resources'])
        @elseif ($isDatabase)
            @include('targets._database-overview', ['part' => 'resources'])
        @endif

        {{-- Métriques propres au type (SCRUM-68), recopiées par la synchro : aucun appel Zabbix ici --}}
        @php
            $metricNames = array_keys($target->provisioner()->metricItemKeys());
            $showCertificate = in_array('certificate_days', $metricNames, true) && \App\Monitoring\Provisioners\WebsiteProvisioner::checksCertificate($target);
        @endphp
        @if (in_array('http_code', $metricNames, true) || $showCertificate)
            <div class="row g-3 mb-4">
                @if (in_array('http_code', $metricNames, true))
                    @php
                        $httpCode = $target->lastMetric('http_code');
                    @endphp
                    <div class="col-6 col-lg-3">
                        <div class="card kpi-card h-100"><div class="card-body">
                            <span class="kpi-label">HTTP code</span>
                            @if ($httpCode)
                                <div class="kpi-value {{ $httpCode >= 200 && $httpCode < 300 ? 'text-success' : 'text-danger' }}">{{ $httpCode }}</div>
                            @else
                                <div class="kpi-value text-body-secondary">—</div>
                                <span class="small text-body-secondary">No response yet</span>
                            @endif
                        </div></div>
                    </div>
                @endif
                @if ($showCertificate)
                    @php
                        $days = $target->lastMetric('certificate_days');
                        $certificateColor = match (true) {
                            $days === null => 'text-body-secondary',
                            $days < \App\Monitoring\Provisioners\WebsiteProvisioner::CERTIFICATE_CRITICAL_DAYS => 'text-danger',
                            $days < \App\Monitoring\Provisioners\WebsiteProvisioner::CERTIFICATE_WARNING_DAYS => 'text-warning',
                            default => 'text-success',
                        };
                    @endphp
                    <div class="col-6 col-lg-3">
                        <div class="card kpi-card h-100"><div class="card-body">
                            <span class="kpi-label">SSL certificate</span>
                            @if ($days === null)
                                <div class="kpi-value text-body-secondary">—</div>
                                <span class="small text-body-secondary">Not read yet</span>
                            @elseif ($days < 0)
                                <div class="kpi-value text-danger">Expired</div>
                                <span class="small text-body-secondary">{{ number_format(abs($days)) }} {{ Str::plural('day', abs($days)) }} ago</span>
                            @else
                                <div class="kpi-value {{ $certificateColor }}">{{ number_format($days) }}<small class="fs-6 text-body-secondary ms-1">{{ Str::plural('day', $days) }} left</small></div>
                            @endif
                        </div></div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Graphique (SCRUM-44, séries du type en SCRUM-72) : données chargées en JS depuis l'API interne --}}
        @php
            $chartSeries = $target->provisioner()->chartSeries();
        @endphp
        @if ($chartSeries !== [])
        <div class="card mb-4" data-metric-chart data-url="{{ route('targets.metrics', [$target, array_key_first($chartSeries)]) }}" data-period="1h">
            <div class="card-header d-flex flex-wrap align-items-center gap-2 py-3">
                <span class="fw-semibold" data-chart-title>{{ reset($chartSeries)['label'] }}</span>
                @if (count($chartSeries) > 1)
                    <div class="btn-group btn-group-sm" role="group" aria-label="Metric">
                        @foreach ($chartSeries as $metric => $series)
                            <button type="button" class="btn btn-outline-primary" data-metric-url="{{ route('targets.metrics', [$target, $metric]) }}" aria-pressed="false">{{ $series['label'] }}</button>
                        @endforeach
                    </div>
                @endif
                <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Period">
                    @foreach (\App\Services\MetricsService::OVERVIEW_PERIODS as $period)
                        <button type="button" class="btn btn-outline-secondary" data-period="{{ $period }}" aria-pressed="false">{{ $period }}</button>
                    @endforeach
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3 small">
                    @foreach (['avg' => 'Average', 'min' => 'Minimum', 'max' => 'Maximum', 'count' => 'Samples'] as $key => $label)
                        <div class="col-6 col-md-3">
                            <div class="kpi-label">{{ $label }}</div>
                            <div class="fs-5 fw-semibold" data-stat="{{ $key }}">—</div>
                        </div>
                    @endforeach
                </div>
                <div class="position-relative" data-chart-frame style="height: 280px; transition: opacity .15s ease">
                    <canvas role="img" aria-label="{{ reset($chartSeries)['label'] }} chart"></canvas>
                    <div class="position-absolute top-50 start-50 translate-middle small text-body-secondary text-center d-none" data-chart-message role="status"></div>
                </div>
            </div>
        </div>

        @endif

        <div class="card">
            <div class="card-header d-flex align-items-center py-3">
                <span class="fw-semibold">Active alerts</span>
                <span class="badge {{ $activeAlerts->isNotEmpty() ? 'text-bg-danger' : 'text-bg-secondary' }} ms-2">{{ $activeAlerts->count() }}</span>
                <a href="{{ route('targets.show', [$target, 'tab' => 'alerts']) }}" class="ms-auto small">All alerts<i class="bi bi-arrow-right ms-1"></i></a>
            </div>
            @if ($activeAlerts->isEmpty())
                <div class="card-body text-center py-4">
                    <i class="bi bi-check-circle text-success fs-3"></i>
                    <p class="mb-0 mt-2 small text-body-secondary">No active alert on this target.</p>
                </div>
            @else
                @include('alerts._table', ['alerts' => $activeAlerts, 'showTarget' => false])
            @endif
        </div>
    @elseif ($tab === 'metrics')
        {{-- Onglet Metrics (SCRUM-49) : une carte par série (valeur actuelle, moyenne, min, max, graphique), même période pour toutes --}}
        @include('targets._period-bar')

        @foreach ($target->provisioner()->chartSeries() as $metric => $series)
            <div class="card mb-4" data-metric-chart data-url="{{ route('targets.metrics', [$target, $metric]) }}" data-query="{{ json_encode($range->query()) }}">
                <div class="card-header py-3"><span class="fw-semibold" data-chart-title>{{ $series['label'] }}</span></div>
                <div class="card-body">
                    <div class="row g-3 mb-3 small">
                        @foreach (['last' => 'Current', 'avg' => 'Average', 'min' => 'Minimum', 'max' => 'Maximum', 'count' => 'Samples'] as $key => $label)
                            <div class="col-6 col-md">
                                <div class="kpi-label">{{ $label }}</div>
                                <div class="fs-5 fw-semibold" data-stat="{{ $key }}">—</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="position-relative" data-chart-frame style="height: 240px; transition: opacity .15s ease">
                        <canvas role="img" aria-label="{{ $series['label'] }} chart"></canvas>
                        <div class="position-absolute top-50 start-50 translate-middle small text-body-secondary text-center d-none" data-chart-message role="status"></div>
                    </div>
                </div>
            </div>
        @endforeach
    @elseif ($tab === 'history')
        {{-- Onglet History (SCRUM-50) : disponibilité, frise et liste des incidents ; « — » quand il n'y a pas de mesure --}}
        {{-- Statistiques précalculées chaque heure (SCRUM-51) : aucune lecture de Zabbix ici --}}
        @php
            $latest = $history['summaries']->pluck('computed_at')->filter()->max();
        @endphp
        <div class="d-flex align-items-baseline gap-2 mb-2">
            <p class="section-label mb-0">Availability</p>
            <span class="small text-body-secondary ms-auto">
                {{ $latest ? 'Updated '.$latest->toDisplay()->format('d/m H:i').' · computed every hour' : 'Statistics are computed every hour.' }}
            </span>
        </div>
        <div class="row g-3 mb-3">
            @foreach ($history['summaries'] as $days => $summary)
                <div class="col-12 col-md-4">
                    <div class="card kpi-card h-100"><div class="card-body">
                        <span class="kpi-label">{{ $days === 1 ? 'Today' : "Last {$days} days" }}</span>
                        <div class="kpi-value {{ \App\Support\StatFormat::availabilityColor($summary['availability']) }}" data-availability="{{ $days }}">
                            @if ($summary['availability'] === null)
                                —
                            @else
                                {{ \App\Support\StatFormat::percent($summary['availability']) }}<small class="fs-6 text-body-secondary ms-1">%</small>
                            @endif
                        </div>
                        <span class="small text-body-secondary">{{ $summary['availability'] === null ? 'No measure over this period' : 'available (checks and downtime)' }}</span>
                    </div></div>
                </div>
            @endforeach
        </div>
        @php
            $month = $history['summaries'][30];
            $week = $history['summaries'][7];
        @endphp
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="card kpi-card h-100"><div class="card-body">
                    <span class="kpi-label">Incidents · 30 days</span>
                    <div class="kpi-value" data-stat="incidents">{{ $month['computed_at'] ? number_format($month['incidents']) : '—' }}</div>
                </div></div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card kpi-card h-100"><div class="card-body">
                    <span class="kpi-label">Mean time to resolve · 30 days</span>
                    <div class="kpi-value" data-stat="mttr">{{ \App\Support\StatFormat::duration($month['mttr_seconds']) ?? '—' }}</div>
                </div></div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card kpi-card h-100"><div class="card-body">
                    <span class="kpi-label">Average response · 7 days</span>
                    <div class="kpi-value" data-stat="response">
                        @if ($week['avg_response_ms'] === null)
                            —
                        @else
                            {{ number_format($week['avg_response_ms']) }}<small class="fs-6 text-body-secondary ms-1">ms</small>
                        @endif
                    </div>
                </div></div>
            </div>
        </div>
        @if ($history['zabbixFailed'])
            <div class="alert alert-warning d-flex align-items-center gap-2" role="status">
                <i class="bi bi-exclamation-triangle"></i>
                <span>Some values are temporarily unavailable from the monitoring service.</span>
            </div>
        @endif

        @include('targets._period-bar', ['periods' => ['24h', '7d', '30d']])

        <div class="card mb-4">
            <div class="card-header d-flex align-items-center py-3">
                <span class="fw-semibold">Incidents</span>
                <span class="badge {{ $history['total'] > 0 ? 'text-bg-danger' : 'text-bg-secondary' }} ms-2">{{ $history['total'] }}</span>
            </div>
            <div class="card-body">
                @php
                    $span = max(1, $range->to - $range->from);
                    $timezone = config('app.display_timezone');
                @endphp
                <div class="incident-timeline" role="img" aria-label="{{ $history['total'] }} {{ \Illuminate\Support\Str::plural('incident', $history['total']) }}, {{ \Illuminate\Support\Str::lower($range->label()) }}">
                    @foreach ($history['timeline'] as $incident)
                        @php
                            $start = max($incident->started_at->timestamp, $range->from);
                            $end = min(($incident->resolved_at ?? now())->timestamp, $range->to);
                            $left = ($start - $range->from) / $span * 100;
                            $width = max(0, $end - $start) / $span * 100;
                        @endphp
                        <span class="incident-segment incident-segment-{{ $incident->severity->value }}" style="left: {{ round($left, 3) }}%; width: {{ round($width, 3) }}%"
                              title="{{ $incident->severity->label() }} · {{ $incident->message }} · {{ $incident->started_at->toDisplay()->format('d/m H:i') }} → {{ $incident->resolved_at?->toDisplay()->format('d/m H:i') ?? 'ongoing' }}"></span>
                    @endforeach
                </div>
                <div class="d-flex justify-content-between small text-body-secondary mt-1">
                    <span>{{ \Carbon\CarbonImmutable::createFromTimestamp($range->from, $timezone)->format('d/m H:i') }}</span>
                    <span>{{ \Carbon\CarbonImmutable::createFromTimestamp($range->to, $timezone)->format('d/m H:i') }}</span>
                </div>
            </div>

            @if ($history['incidents']->isEmpty())
                <div class="card-body text-center pt-0 pb-4">
                    <i class="bi bi-check-circle text-success fs-3"></i>
                    <p class="mb-0 mt-2 small text-body-secondary">No incident over this period.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">Started</th>
                                <th>Resolved</th>
                                <th class="text-end">Duration</th>
                                <th>Severity</th>
                                <th class="text-end">Maximum</th>
                                <th class="text-end pe-3">Threshold</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history['incidents'] as $incident)
                                <tr>
                                    <td class="ps-3 small text-nowrap"><time datetime="{{ $incident->started_at->toIso8601String() }}">{{ $incident->started_at->toDisplay()->format('d/m/Y H:i') }}</time></td>
                                    <td class="small text-nowrap">
                                        @if ($incident->resolved_at)
                                            <time datetime="{{ $incident->resolved_at->toIso8601String() }}">{{ $incident->resolved_at->toDisplay()->format('d/m/Y H:i') }}</time>
                                        @else
                                            <span class="text-danger">Ongoing</span>
                                        @endif
                                    </td>
                                    <td class="text-end small text-nowrap">{{ $incident->durationForHumans() }}</td>
                                    <td class="text-nowrap">
                                        <x-severity-badge :severity="$incident->severity" />
                                        <span class="small text-body-secondary ms-1">{{ $incident->metricLabel() }}</span>
                                    </td>
                                    <td class="text-end small text-nowrap fw-semibold">{{ $incident->peakLabel($history['peaks'][$incident->id] ?? null) ?? '—' }}</td>
                                    <td class="text-end small text-nowrap text-body-secondary pe-3">{{ $incident->thresholdLabel() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($history['incidents']->hasPages())
                    <div class="card-footer pt-3 small">{{ $history['incidents']->onEachSide(1)->links() }}</div>
                @endif
            @endif
        </div>
    @elseif ($tab === 'alerts')
        <div class="card">
            @if ($alerts->isEmpty())
                <div class="card-body text-center py-5">
                    <i class="bi bi-bell-slash text-body-secondary" style="font-size: 2.5rem"></i>
                    <p class="mt-3 mb-1 fw-semibold">No alert yet</p>
                    <p class="text-body-secondary small mb-0">Alerts appear here when Zabbix detects a problem on this target.</p>
                </div>
            @else
                @include('alerts._table', ['showTarget' => false])
            @endif
        </div>
    @else
        <div class="card">
            <div class="card-body">
                <p class="section-label mb-3">Check</p>
                <dl class="row small">
                    <dt class="col-sm-3 fw-normal text-body-secondary">Type</dt>
                    <dd class="col-sm-9"><i class="bi {{ $target->type->icon() }} me-1"></i>{{ $target->type->label() }}</dd>
                    <dt class="col-sm-3 fw-normal text-body-secondary">{{ $target->url !== null ? 'URL' : 'Address' }}</dt>
                    <dd class="col-sm-9 font-monospace-sm text-break">{{ $target->displayAddress() }}</dd>
                    <dt class="col-sm-3 fw-normal text-body-secondary">Check interval</dt>
                    <dd class="col-sm-9">{{ $intervals[$target->check_interval] ?? $target->check_interval.' s' }}</dd>
                    @if ($target->warning_threshold_ms !== null)
                        <dt class="col-sm-3 fw-normal text-body-secondary">Thresholds</dt>
                        <dd class="col-sm-9">
                            <span class="text-warning">Warning &gt; {{ number_format($target->warning_threshold_ms) }} ms</span> ·
                            <span class="text-danger">Critical &gt; {{ number_format($target->critical_threshold_ms) }} ms</span> ·
                            Unavailable if the response is not 2xx
                        </dd>
                    @endif
                    @if ($target->description)
                        <dt class="col-sm-3 fw-normal text-body-secondary">Description</dt>
                        <dd class="col-sm-9">{{ $target->description }}</dd>
                    @endif
                </dl>

                <p class="section-label mb-3">Zabbix</p>
                <dl class="row small mb-0">
                    <dt class="col-sm-3 fw-normal text-body-secondary">Host</dt>
                    <dd class="col-sm-9 font-monospace-sm">{{ $target->zabbixHostName() }} <span class="text-body-secondary">(id {{ $target->zabbix_host_id ?? '—' }})</span></dd>
                    <dt class="col-sm-3 fw-normal text-body-secondary">Host group</dt>
                    <dd class="col-sm-9 font-monospace-sm">{{ $target->team->zabbixHostGroupName() }}</dd>
                    <dt class="col-sm-3 fw-normal text-body-secondary">Checks</dt>
                    <dd class="col-sm-9 mb-0">
                        @foreach ($target->provisioner()->checkSummary($target) as $check)
                            <div>{{ $check }}</div>
                        @endforeach
                    </dd>
                </dl>
            </div>
        </div>
    @endif

    @can('delete', $target)
        {{-- Confirmation de suppression (SCRUM-35) --}}
        <div class="modal fade" id="delete-target" tabindex="-1" aria-labelledby="delete-target-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="{{ route('targets.destroy', $target) }}" class="modal-content">
                    @csrf
                    @method('DELETE')
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="delete-target-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Delete {{ $target->name }}?</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body small">
                        <p>This stops the monitoring and permanently deletes:</p>
                        <ul class="mb-0">
                            <li>the Zabbix host <code>{{ $target->zabbixHostName() }}</code>, its check and its measurement history;</li>
                            <li>the {{ $alertCount }} {{ \Illuminate\Support\Str::plural('alert', $alertCount) }} of this target.</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete target</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
