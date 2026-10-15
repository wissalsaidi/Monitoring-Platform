@extends('layouts.app')

@section('title', 'Monitoring targets')

@section('actions')
    @if ($canCreate)
        <a href="{{ route('targets.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Add Monitoring Target
        </a>
    @endif
@endsection

@section('content')
    {{-- Filtres (SCRUM-34) : type + recherche par nom, en GET pour garder des URLs partageables --}}
    <form method="GET" action="{{ route('targets.index') }}" class="row g-2 align-items-center mb-3" role="search">
        <div class="col-12 col-md-5 col-lg-4">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Search by name" aria-label="Search by name" maxlength="100">
            </div>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <select name="status" class="form-select form-select-sm" aria-label="Filter by status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <select name="type" class="form-select form-select-sm" aria-label="Filter by type" onchange="this.form.submit()">
                <option value="">All types</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(($filters['type'] ?? null) === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-4 col-md-auto d-flex gap-2">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
            @if (array_filter($filters))
                <a href="{{ route('targets.index') }}" class="btn btn-link btn-sm text-body-secondary">Reset</a>
            @endif
        </div>
    </form>

    <div class="card">
        <div class="card-header d-flex align-items-center py-3">
            <span class="fw-semibold">Targets</span>
            <span class="badge text-bg-secondary ms-2">{{ $targets->total() }}</span>
        </div>

        @if ($targets->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-hdd-network text-body-secondary" style="font-size: 2.5rem"></i>
                @if (array_filter($filters))
                    <p class="mt-3 mb-1 fw-semibold">No target matches these filters</p>
                    <a href="{{ route('targets.index') }}" class="small">Show all targets</a>
                @else
                    <p class="mt-3 mb-1 fw-semibold">No target monitored yet</p>
                    <p class="text-body-secondary small mb-3">Add your first API and Zabbix will start checking it automatically.</p>
                    @if ($canCreate)
                        <a href="{{ route('targets.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Monitoring Target</a>
                    @endif
                @endif
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th class="text-end">Response time</th>
                            <th class="text-end pe-3">Last check</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($targets as $target)
                            <tr>
                                <td class="ps-3">
                                    <a href="{{ route('targets.show', $target) }}" class="fw-semibold text-body text-decoration-none">{{ $target->name }}</a>
                                    <div class="font-monospace-sm text-body-secondary text-truncate" style="max-width: 360px">{{ $target->url }}</div>
                                </td>
                                <td class="small text-body-secondary text-nowrap"><i class="bi {{ $target->type->icon() }} me-1"></i>{{ $target->type->label() }}</td>
                                <td><x-status-pill :status="$target->status" /></td>
                                <td class="text-end small text-nowrap">
                                    @if ($target->last_response_ms !== null)
                                        @php
                                            $color = match (true) {
                                                $target->last_response_ms > $target->critical_threshold_ms => 'text-danger',
                                                $target->last_response_ms > $target->warning_threshold_ms => 'text-warning',
                                                default => '',
                                            };
                                        @endphp
                                        <span class="{{ $color }}">{{ number_format($target->last_response_ms) }} ms</span>
                                    @else
                                        <span class="text-body-secondary">—</span>
                                    @endif
                                </td>
                                <td class="text-end pe-3 small text-body-secondary text-nowrap">
                                    @if ($target->last_checked_at)
                                        <time datetime="{{ $target->last_checked_at->toIso8601String() }}" title="{{ $target->last_checked_at->toDisplay()->format('d/m/Y H:i:s') }}">{{ $target->last_checked_at->diffForHumans() }}</time>
                                    @else
                                        Never
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($targets->hasPages())
                <div class="card-footer pt-3 small">
                    {{ $targets->onEachSide(1)->links() }}
                </div>
            @endif
        @endif
    </div>
@endsection
