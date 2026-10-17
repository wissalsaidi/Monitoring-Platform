@extends('layouts.app')

@section('title', 'Audit log')

@section('actions')
    @if ($teams->count() > 1)
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-people me-1"></i>{{ $team->name }}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                @foreach ($teams as $other)
                    <li><a class="dropdown-item @if ($other->is($team)) active @endif" href="{{ route('teams.audit.index', $other) }}">{{ $other->name }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection

@section('content')
    {{-- Filtres (SCRUM-60) en GET : URL partageable --}}
    <form method="GET" action="{{ route('teams.audit.index', $team) }}" class="row g-2 align-items-end mb-3" role="search">
        <div class="col-12 col-md-3">
            <label for="action" class="form-label small text-body-secondary mb-1">Action</label>
            <select id="action" name="action" class="form-select form-select-sm">
                <option value="">All actions</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>{{ \App\Models\AuditLog::ACTIONS[$action] ?? $action }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label for="user" class="form-label small text-body-secondary mb-1">Author</label>
            <select id="user" name="user" class="form-select form-select-sm">
                <option value="">Everyone</option>
                @foreach ($authors as $author)
                    <option value="{{ $author->id }}" @selected((int) ($filters['user'] ?? 0) === $author->id)>{{ $author->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="from" class="form-label small text-body-secondary mb-1">From</label>
            <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm @error('from') is-invalid @enderror">
        </div>
        <div class="col-6 col-md-2">
            <label for="to" class="form-label small text-body-secondary mb-1">To</label>
            <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm @error('to') is-invalid @enderror">
        </div>
        <div class="col-12 col-md-auto d-flex gap-2">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
            @if (array_filter($filters))
                <a href="{{ route('teams.audit.index', $team) }}" class="btn btn-link btn-sm text-body-secondary">Reset</a>
            @endif
        </div>
        @if ($errors->any())
            <div class="col-12 text-danger small">{{ $errors->first() }}</div>
        @endif
    </form>

    <div class="card">
        <div class="card-header d-flex align-items-center py-3">
            <span class="fw-semibold">{{ $team->name }}</span>
            <span class="badge text-bg-secondary ms-2">{{ $logs->total() }}</span>
            <span class="ms-auto small text-body-secondary">Entries are kept {{ config('audit.retention_days') }} days</span>
        </div>

        @if ($logs->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-journal-text text-body-secondary" style="font-size: 2.5rem"></i>
                <p class="mt-3 mb-1 fw-semibold">{{ array_filter($filters) ? 'No entry matches these filters' : 'No action recorded yet' }}</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3 text-nowrap">Date</th>
                            <th>Author</th>
                            <th>Action</th>
                            <th>Object</th>
                            <th class="pe-3">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="ps-3 small text-nowrap">{{ $log->created_at->toDisplay()->format('d/m/Y H:i:s') }}</td>
                                <td class="small">
                                    {{ $log->user?->name ?? 'Deleted user' }}
                                    @if ($log->ip_address)<div class="text-body-secondary">{{ $log->ip_address }}</div>@endif
                                </td>
                                <td><span class="badge text-bg-secondary fw-normal">{{ $log->actionLabel() }}</span></td>
                                <td class="small">
                                    @if ($log->subject instanceof \App\Models\MonitoringTarget)
                                        <a href="{{ route('targets.show', $log->subject) }}">{{ $log->subjectLabel() }}</a>
                                    @else
                                        {{ $log->subjectLabel() }}
                                    @endif
                                </td>
                                <td class="pe-3 small">
                                    @foreach ($log->detailLines() as $line)
                                        <div>
                                            <span class="text-body-secondary">{{ $line['label'] }}:</span>
                                            @if (array_key_exists('new', $line))
                                                <span class="text-decoration-line-through text-body-secondary">{{ $line['old'] }}</span>
                                                <i class="bi bi-arrow-right mx-1"></i>{{ $line['new'] }}
                                            @else
                                                {{ $line['value'] }}
                                            @endif
                                        </div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($logs->hasPages())
                <div class="card-footer">{{ $logs->links() }}</div>
            @endif
        @endif
    </div>
@endsection
