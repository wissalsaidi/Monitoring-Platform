{{--
    Page Overview d'une base de données MySQL (SCRUM-71) : valeurs recopiées par la synchro (last_metrics), aucun appel
    Zabbix. part = identity (cartes à côté du statut et du temps de réponse) ou resources. Le mot de passe n'est jamais affiché.
--}}
@if ($part === 'identity')
    @php
        $version = $target->lastMetric('version');
    @endphp
    <div class="col-6 col-lg-3">
        <div class="card kpi-card h-100"><div class="card-body">
            <span class="kpi-label">MySQL</span>
            <div class="kpi-value {{ is_string($version) && $version !== '' ? '' : 'text-body-secondary' }}">{{ is_string($version) && $version !== '' ? $version : '—' }}</div>
            <span class="small text-body-secondary">Server version</span>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card h-100"><div class="card-body">
            <span class="kpi-label">Host</span>
            <div class="fs-6 fw-semibold font-monospace-sm my-2 text-break">{{ $target->setting('address') }}:{{ $target->setting('port') }}</div>
            <span class="small text-body-secondary text-break">User {{ $target->setting('username') }}</span>
        </div></div>
    </div>
@else
    @php
        $connections = $target->lastMetric('connections');
        $threshold = (int) $target->setting('connections_warning');
        $queries = $target->lastMetric('queries_per_sec');
        $slow = $target->lastMetric('slow_queries');
        $slowPerMinute = $target->lastMetric('slow_queries_per_min');
        $uptime = $target->lastMetric('uptime');
    @endphp
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">Connections</span>
                <div class="kpi-value {{ is_numeric($connections) ? ($connections > $threshold ? 'text-warning' : 'text-success') : 'text-body-secondary' }}">{{ is_numeric($connections) ? number_format($connections) : '—' }}</div>
                <span class="small text-body-secondary">Warning &gt; {{ number_format($threshold) }}</span>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">Queries</span>
                <div class="kpi-value {{ is_numeric($queries) ? '' : 'text-body-secondary' }}">{{ is_numeric($queries) ? number_format($queries, 2) : '—' }}<small class="fs-6 text-body-secondary ms-1">/ s</small></div>
                <span class="small text-body-secondary">Statements received by the server</span>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card kpi-card h-100"><div class="card-body">
                <span class="kpi-label">Slow queries</span>
                <div class="kpi-value {{ is_numeric($slowPerMinute) && $slowPerMinute > 0 ? 'text-warning' : (is_numeric($slowPerMinute) ? '' : 'text-body-secondary') }}">{{ is_numeric($slowPerMinute) ? number_format($slowPerMinute, 1) : '—' }}<small class="fs-6 text-body-secondary ms-1">/ min</small></div>
                <span class="small text-body-secondary">{{ is_numeric($slow) ? number_format($slow).' since startup' : 'Not read yet' }}</span>
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
    </div>
@endif
