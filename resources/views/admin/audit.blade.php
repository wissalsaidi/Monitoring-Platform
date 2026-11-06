@extends('layouts.app')

@section('title', 'Platform audit')

@section('content')
    @include('admin._tabs')

    {{-- Filtres en GET : URL partageable --}}
    <form method="GET" action="{{ route('admin.audit') }}" class="row g-2 align-items-end mb-3" role="search">
        <div class="col-12 col-md-2">
            <label for="team" class="form-label small text-body-secondary mb-1">Team</label>
            <select id="team" name="team" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="{{ \App\Http\Controllers\Admin\AuditController::PLATFORM }}" @selected(($filters['team'] ?? null) === \App\Http\Controllers\Admin\AuditController::PLATFORM)>Platform only</option>
                @foreach ($teams as $team)
                    <option value="{{ $team->id }}" @selected(($filters['team'] ?? null) === (string) $team->id)>{{ $team->name }} #{{ $team->id }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label for="action" class="form-label small text-body-secondary mb-1">Action</label>
            <select id="action" name="action" class="form-select form-select-sm">
                <option value="">All actions</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>{{ \App\Models\AuditLog::ACTIONS[$action] ?? $action }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-2">
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
                <a href="{{ route('admin.audit') }}" class="btn btn-link btn-sm text-body-secondary">Reset</a>
            @endif
        </div>
        @if ($errors->any())
            <div class="col-12 text-danger small">{{ $errors->first() }}</div>
        @endif
    </form>

    <div class="card">
        <div class="card-header d-flex align-items-center py-3">
            <span class="fw-semibold">All teams and platform actions</span>
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
                @include('audit._table', ['showTeam' => true])
            </div>
            @if ($logs->hasPages())
                <div class="card-footer">{{ $logs->onEachSide(1)->links() }}</div>
            @endif
        @endif
    </div>
@endsection
