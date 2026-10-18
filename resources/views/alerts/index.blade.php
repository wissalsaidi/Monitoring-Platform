@extends('layouts.app')

@section('title', 'Alerts')

@section('content')
    {{-- Onglets Actives / Historique (SCRUM-41) : les filtres suivent d'un onglet à l'autre --}}
    <ul class="nav nav-underline mb-3">
        <li class="nav-item">
            <a href="{{ route('alerts.index', $filters) }}" class="nav-link {{ $history ? '' : 'active' }}" @if (! $history) aria-current="page" @endif>
                <i class="bi bi-bell me-1"></i>Active <span class="badge {{ $counts['active'] > 0 ? 'text-bg-danger' : 'text-bg-secondary' }} ms-1">{{ $counts['active'] }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="{{ route('alerts.history', $filters) }}" class="nav-link {{ $history ? 'active' : '' }}" @if ($history) aria-current="page" @endif>
                <i class="bi bi-clock-history me-1"></i>History <span class="badge text-bg-secondary ms-1">{{ $counts['history'] }}</span>
            </a>
        </li>
    </ul>

    <form method="GET" action="{{ $history ? route('alerts.history') : route('alerts.index') }}" class="row g-2 align-items-center mb-3">
        <div class="col-6 col-md-3">
            <select name="severity" class="form-select form-select-sm" aria-label="Filter by severity" data-auto-submit>
                <option value="">All severities</option>
                @foreach ($severities as $severity)
                    <option value="{{ $severity->value }}" @selected(($filters['severity'] ?? null) === $severity->value)>{{ $severity->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-4">
            <select name="target" class="form-select form-select-sm" aria-label="Filter by target" data-auto-submit>
                <option value="">All targets</option>
                @foreach ($targets as $target)
                    <option value="{{ $target->id }}" @selected((int) ($filters['target'] ?? 0) === $target->id)>{{ $target->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto d-flex gap-2">
            <noscript><button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button></noscript>
            @if (array_filter($filters))
                <a href="{{ $history ? route('alerts.history') : route('alerts.index') }}" class="btn btn-link btn-sm text-body-secondary">Reset</a>
            @endif
        </div>
    </form>

    <div class="card">
        @if ($alerts->isEmpty())
            <div class="card-body text-center py-5">
                @if (array_filter($filters))
                    <i class="bi bi-funnel text-body-secondary" style="font-size: 2.5rem"></i>
                    <p class="mt-3 mb-1 fw-semibold">No alert matches these filters</p>
                @elseif ($history)
                    <i class="bi bi-clock-history text-body-secondary" style="font-size: 2.5rem"></i>
                    <p class="mt-3 mb-1 fw-semibold">No resolved alert yet</p>
                    <p class="text-body-secondary small mb-0">Incidents appear here once Zabbix has resolved them.</p>
                @else
                    <i class="bi bi-check-circle text-success" style="font-size: 2.5rem"></i>
                    <p class="mt-3 mb-1 fw-semibold">All clear</p>
                    <p class="text-body-secondary small mb-0">No active alert. Zabbix problems are synchronized every minute.</p>
                @endif
            </div>
        @else
            @include('alerts._table', ['showTarget' => true])
        @endif
    </div>
@endsection
