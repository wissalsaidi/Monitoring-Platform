@extends('layouts.app')

@section('title', 'Teams')

@section('content')
    @include('admin._tabs')

    <div class="row g-4">
        <div class="col-xxl-9">
            <div class="card">
                <div class="card-header d-flex align-items-center py-3">
                    <span class="fw-semibold">All teams</span>
                    <span class="badge text-bg-secondary ms-2">{{ $teams->count() }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">Team</th>
                                <th>Admins</th>
                                <th class="text-end">Members</th>
                                <th class="text-end">Targets</th>
                                <th class="text-end">Active alerts</th>
                                <th class="pe-3 d-none d-md-table-cell">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($teams as $team)
                                <tr>
                                    <td class="ps-3">
                                        <a href="{{ route('admin.teams.show', $team) }}" class="fw-semibold text-decoration-none">{{ $team->name }}</a>
                                        <span class="small text-body-secondary">#{{ $team->id }}</span>
                                    </td>
                                    <td class="small">
                                        @forelse ($team->members as $admin)
                                            <a href="{{ route('admin.users.show', $admin) }}" class="text-decoration-none">{{ $admin->name }}</a>@unless ($admin->isActive())<span class="badge text-bg-danger ms-1">Deactivated</span>@endunless{{ $loop->last ? '' : ',' }}
                                        @empty
                                            <span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>No Admin</span>
                                        @endforelse
                                    </td>
                                    <td class="text-end">{{ $team->members_count }}</td>
                                    <td class="text-end">{{ $team->monitoring_targets_count }}</td>
                                    <td class="text-end">
                                        @if ($team->active_alerts_count > 0)
                                            <span class="badge text-bg-danger">{{ $team->active_alerts_count }}</span>
                                        @else
                                            <span class="text-body-secondary">0</span>
                                        @endif
                                    </td>
                                    <td class="pe-3 d-none d-md-table-cell small text-body-secondary">{{ $team->created_at->toDisplay()->format('d/m/Y') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-body-secondary py-4">No team yet. Create the first one.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xxl-3">
            <form method="POST" action="{{ route('admin.teams.store') }}" class="card" novalidate>
                @csrf
                <div class="card-header py-3 fw-semibold"><i class="bi bi-plus-circle me-1"></i>Create a team</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="team-name" class="form-label">Name</label>
                        <input id="team-name" type="text" name="name" value="{{ old('name') }}" maxlength="100"
                               class="form-control @error('name') is-invalid @enderror" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="team-admin" class="form-label">First Admin (email)</label>
                        <input id="team-admin" type="email" name="admin_email" value="{{ old('admin_email') }}"
                               class="form-control @error('admin_email') is-invalid @enderror" required>
                        @error('admin_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">An existing account becomes Admin right away. Otherwise, the address receives an invitation as Admin.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Create the team</button>
                    <p class="form-text mb-0 mt-2">Its Zabbix host group and Grafana organization are created at the same time.</p>
                </div>
            </form>
        </div>
    </div>
@endsection
