{{-- Choix de la période, commun aux onglets Metrics (SCRUM-49) et History (SCRUM-50) : boutons, ou dates de début et de fin --}}
@php
    $today = now()->setTimezone(config('app.display_timezone'))->toDateString();
    $periods = $periods ?? array_keys(\App\Services\MetricsService::PERIODS);
@endphp
<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-end gap-3">
        <div>
            <div class="kpi-label mb-1">Period</div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Period">
                @foreach ($periods as $period)
                    <a class="btn btn-outline-secondary {{ $range->period === $period ? 'active' : '' }}" @if ($range->period === $period) aria-current="true" @endif
                       href="{{ route('targets.show', [$target, 'tab' => $tab, 'period' => $period]) }}">{{ $period }}</a>
                @endforeach
            </div>
        </div>
        <form method="GET" action="{{ route('targets.show', $target) }}" class="d-flex flex-wrap align-items-end gap-2" aria-label="Custom period">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div>
                <label for="{{ $tab }}-from" class="kpi-label d-block mb-1">From</label>
                <input type="date" id="{{ $tab }}-from" name="from" class="form-control form-control-sm" required max="{{ $today }}" value="{{ $range->fromDate }}">
            </div>
            <div>
                <label for="{{ $tab }}-to" class="kpi-label d-block mb-1">To</label>
                <input type="date" id="{{ $tab }}-to" name="to" class="form-control form-control-sm" required max="{{ $today }}" value="{{ $range->toDate }}">
            </div>
            <button type="submit" class="btn btn-sm {{ $range->isCustom() ? 'btn-primary' : 'btn-outline-primary' }}">Apply</button>
        </form>
        <span class="ms-lg-auto small text-body-secondary"><i class="bi bi-calendar3 me-1"></i>{{ $range->label() }}</span>
    </div>
    @if ($rangeErrors)
        <div class="alert alert-warning small mx-3 mb-3" role="alert">
            {{ implode(' ', $rangeErrors->all()) }} Showing the {{ \Illuminate\Support\Str::lower($range->label()) }}.
        </div>
    @endif
</div>
