{{--
    Page Overview d'un conteneur Docker (SCRUM-70) : valeurs recopiées par la synchro (last_metrics), aucun appel Zabbix.
    part = identity (cartes à côté du statut) ou resources (CPU, mémoire, réseau, redémarrages).
--}}
@php
    $formatBytes = function (int|float|null $bytes, string $suffix = ''): string {
        if (! is_numeric($bytes)) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i === 0 ? 0 : 1).' '.$units[$i].$suffix;
    };
@endphp

@if ($part === 'identity')
    @php
        $state = $target->lastMetric('state');
        $uptime = $target->lastMetric('uptime');
    @endphp
    <div class="col-6 col-lg-3">
        <div class="card kpi-card h-100"><div class="card-body">
            <span class="kpi-label">Container state</span>
            @if (is_string($state) && $state !== '')
                <div class="kpi-value {{ $state === 'running' ? 'text-success' : 'text-danger' }}">{{ ucfirst($state) }}</div>
            @else
                <div class="kpi-value text-body-secondary">—</div>
                <span class="small text-body-secondary">Not read yet</span>
            @endif
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card h-100"><div class="card-body">
            <span class="kpi-label">Container</span>
            <div class="fs-6 fw-semibold font-monospace-sm my-2 text-break">{{ $target->setting('container') }}</div>
            <span class="small text-body-secondary text-break">on {{ $target->setting('address') }}:{{ $target->setting('port') }}</span>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card h-100"><div class="card-body">
            <span class="kpi-label">Uptime</span>
            @if (is_numeric($uptime) && $uptime > 0)
                <div class="kpi-value">{{ \Carbon\CarbonInterval::seconds((int) $uptime)->cascade()->forHumans(['parts' => 2, 'short' => true]) }}</div>
            @else
                <div class="kpi-value text-body-secondary">—</div>
                <span class="small text-body-secondary">{{ $uptime === 0 ? 'Not running' : 'Not read yet' }}</span>
            @endif
        </div></div>
    </div>
@else
    @php
        $cpu = $target->lastMetric('cpu');
        $memory = $target->lastMetric('memory');
        $memoryWarning = (int) $target->setting('memory_warning');
        $restarts = $target->lastMetric('restarts');
    @endphp
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">CPU</span>
                <div class="kpi-value {{ is_numeric($cpu) ? '' : 'text-body-secondary' }}">{{ is_numeric($cpu) ? number_format($cpu, 1) : '—' }}<small class="fs-6 text-body-secondary ms-1">%</small></div>
                <span class="small text-body-secondary">Of the host's CPUs</span>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">Memory</span>
                <div class="kpi-value {{ is_numeric($memory) ? ($memory > $memoryWarning * 1048576 ? 'text-warning' : 'text-success') : 'text-body-secondary' }}">{{ $formatBytes($memory) }}</div>
                <span class="small text-body-secondary">Warning &gt; {{ number_format($memoryWarning) }} MB</span>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">Network</span>
                <div class="small my-2">
                    <div><i class="bi bi-arrow-down me-1 text-body-secondary"></i>{{ $formatBytes($target->lastMetric('net_in'), '/s') }}</div>
                    <div><i class="bi bi-arrow-up me-1 text-body-secondary"></i>{{ $formatBytes($target->lastMetric('net_out'), '/s') }}</div>
                </div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">Restarts</span>
                <div class="kpi-value {{ is_numeric($restarts) ? '' : 'text-body-secondary' }}">{{ is_numeric($restarts) ? number_format($restarts) : '—' }}</div>
                <span class="small text-body-secondary">Since the container was created</span>
            </div></div>
        </div>
    </div>
@endif
