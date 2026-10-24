{{-- Tableau d'alertes partagé : page Alerts (SCRUM-41) et onglet Alerts d'une target (SCRUM-36) --}}
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead>
            <tr>
                <th class="ps-3">Date</th>
                @if ($showTarget)
                    <th>Target</th>
                @endif
                <th>Severity</th>
                <th class="text-end">Value</th>
                <th class="text-end">Threshold</th>
                <th>Status</th>
                <th class="text-end">Duration</th>
                <th class="pe-3"><span class="visually-hidden">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($alerts as $alert)
                <tr>
                    <td class="ps-3 small text-nowrap">
                        <time datetime="{{ $alert->started_at->toIso8601String() }}">{{ $alert->started_at->toDisplay()->format('d/m H:i') }}</time>
                    </td>
                    @if ($showTarget)
                        <td>
                            <a href="{{ route('targets.show', $alert->target) }}" class="fw-semibold text-body text-decoration-none">{{ $alert->target->name }}</a>
                            <div class="small text-body-secondary">{{ $alert->metricLabel() }}</div>
                        </td>
                    @endif
                    <td class="text-nowrap">
                        <x-severity-badge :severity="$alert->severity" />
                        @unless ($showTarget)
                            <span class="small text-body-secondary ms-1">{{ $alert->metricLabel() }}</span>
                        @endunless
                    </td>
                    <td class="text-end small text-nowrap">{{ $alert->valueLabel() ?? '—' }}</td>
                    <td class="text-end small text-nowrap text-body-secondary">{{ $alert->thresholdLabel() ?? '—' }}</td>
                    <td class="small text-nowrap">
                        @switch($alert->status)
                            @case(\App\Enums\AlertStatus::Open)
                                <span class="text-danger"><i class="bi bi-circle-fill me-1" style="font-size: .5rem"></i>{{ $alert->status->label() }}</span>
                                @break
                            @case(\App\Enums\AlertStatus::Acknowledged)
                                <span class="text-warning"><i class="bi bi-eye-fill me-1"></i>{{ $alert->status->label() }}</span>
                                @if ($alert->acknowledged_at)
                                    <div class="text-body-secondary" title="{{ $alert->acknowledged_at->toDisplay()->format('d/m/Y H:i:s') }}">
                                        by {{ $alert->acknowledger?->name ?? 'Zabbix' }} · {{ $alert->acknowledged_at->toDisplay()->format('d/m H:i') }}
                                    </div>
                                @endif
                                @break
                            @default
                                <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>{{ $alert->status->label() }}</span>
                        @endswitch
                    </td>
                    <td class="text-end small text-nowrap" title="{{ $alert->resolved_at ? 'Resolved '.$alert->resolved_at->toDisplay()->format('d/m/Y H:i:s') : 'Ongoing' }}">
                        @if ($alert->resolved_at)
                            {{ $alert->durationForHumans() }}
                        @else
                            <span class="text-body-secondary">ongoing · </span>{{ $alert->durationForHumans() }}
                        @endif
                    </td>
                    <td class="pe-3 text-end">
                        @can('acknowledge', $alert)
                            <form method="POST" action="{{ route('alerts.acknowledge', $alert) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-warning btn-sm text-nowrap"><i class="bi bi-eye me-1"></i>Acknowledge</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if ($alerts instanceof \Illuminate\Contracts\Pagination\Paginator && $alerts->hasPages())
    <div class="card-footer pt-3 small">
        {{ $alerts->onEachSide(1)->links() }}
    </div>
@endif
