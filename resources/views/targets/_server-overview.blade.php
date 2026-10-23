{{--
    Page Overview d'un serveur (SCRUM-69, cahier des charges §15) : valeurs recopiées par la synchro (last_metrics),
    aucun appel Zabbix. part = identity (cartes à côté du statut) ou resources (CPU, mémoire, disque, charge).
--}}
@php
    $server = \App\Monitoring\Provisioners\ServerProvisioner::class;
@endphp

@if ($part === 'identity')
    @php
        $uptime = $target->lastMetric('uptime');
        $os = $target->lastMetric('os');
    @endphp
    <div class="col-6 col-lg-3">
        <div class="card kpi-card h-100"><div class="card-body">
            <span class="kpi-label">IP / host</span>
            <div class="fs-5 fw-semibold font-monospace-sm my-2 text-break">{{ $target->setting('address') }}</div>
            <span class="small text-body-secondary">Agent port {{ $target->setting('port') }}</span>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card h-100"><div class="card-body">
            <span class="kpi-label">Operating system</span>
            @if (is_string($os) && $os !== '')
                <div class="small fw-semibold my-2 text-break" title="{{ $os }}">{{ Str::limit($os, 90) }}</div>
            @else
                <div class="kpi-value text-body-secondary">—</div>
                <span class="small text-body-secondary">Not read yet</span>
            @endif
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card h-100"><div class="card-body">
            <span class="kpi-label">Uptime</span>
            @if (is_numeric($uptime))
                <div class="kpi-value">{{ \Carbon\CarbonInterval::seconds((int) $uptime)->cascade()->forHumans(['parts' => 2, 'short' => true]) }}</div>
            @else
                <div class="kpi-value text-body-secondary">—</div>
                <span class="small text-body-secondary">Not read yet</span>
            @endif
        </div></div>
    </div>
@else
    <div class="row g-3 mb-4">
        @foreach ($server::RESOURCES as $resource => ['label' => $label])
            @php
                $value = $target->lastMetric($resource);
                $warning = (int) $target->setting("{$resource}_warning");
                $critical = (int) $target->setting("{$resource}_critical");
                $color = match (true) {
                    ! is_numeric($value) => 'text-body-secondary',
                    $value > $critical => 'text-danger',
                    $value > $warning => 'text-warning',
                    default => 'text-success',
                };
                $bar = str_replace('text-', 'bg-', $color);
            @endphp
            <div class="col-6 col-lg-3">
                <div class="card kpi-card h-100"><div class="card-body">
                    <span class="kpi-label">{{ $label }}</span>
                    @if (is_numeric($value))
                        <div class="kpi-value {{ $color }}">{{ number_format($value, 1) }}<small class="fs-6 text-body-secondary ms-1">%</small></div>
                        <div class="progress my-2" style="height: 4px" role="progressbar" aria-label="{{ $label }} usage"
                             aria-valuenow="{{ (int) round($value) }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar {{ $bar }}" style="width: {{ min(100, max(0, $value)) }}%"></div>
                        </div>
                    @else
                        <div class="kpi-value text-body-secondary">—</div>
                    @endif
                    <span class="small text-body-secondary">Warning &gt; {{ $warning }} % · critical &gt; {{ $critical }} %</span>
                </div></div>
            </div>
        @endforeach
        @php
            $load = $target->lastMetric('load');
        @endphp
        <div class="col-6 col-lg-3">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">Load (1 min)</span>
                <div class="kpi-value {{ is_numeric($load) ? '' : 'text-body-secondary' }}">{{ is_numeric($load) ? number_format($load, 2) : '—' }}</div>
                <span class="small text-body-secondary">Average number of running processes</span>
            </div></div>
        </div>
    </div>
@endif
