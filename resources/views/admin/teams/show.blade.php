@extends('layouts.app')

@section('title', $team->name)

@section('actions')
    <a href="{{ route('admin.teams.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>All teams</a>
@endsection

@section('content')
    @include('admin._tabs')

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="row g-3 mb-4">
                @foreach ([['Members', $members->count(), 'bi-people'], ['Targets', $team->monitoring_targets_count, 'bi-hdd-network'], ['Active alerts', $team->active_alerts_count, 'bi-bell']] as [$label, $value, $icon])
                    <div class="col-4">
                        <div class="card"><div class="card-body py-3">
                            <div class="small text-body-secondary"><i class="bi {{ $icon }} me-1"></i>{{ $label }}</div>
                            <div class="h4 mb-0">{{ $value }}</div>
                        </div></div>
                    </div>
                @endforeach
            </div>

            <div class="card mb-4">
                <div class="card-header py-3 fw-semibold">Members <span class="badge text-bg-secondary ms-1">{{ $members->count() }}</span></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <tbody>
                            @forelse ($members as $member)
                                <tr>
                                    <td class="ps-3">
                                        <a href="{{ route('admin.users.show', $member) }}" class="fw-semibold text-decoration-none">{{ $member->name }}</a>
                                        @unless ($member->isActive())<span class="badge text-bg-danger ms-1">Deactivated</span>@endunless
                                        <div class="small text-body-secondary">{{ $member->email }}</div>
                                    </td>
                                    <td>{{ ucfirst($member->pivot->role->value) }}</td>
                                    <td class="pe-3 small text-body-secondary text-end">Since {{ $member->pivot->created_at?->toDisplay()->format('d/m/Y') }}</td>
                                </tr>
                            @empty
                                <tr><td class="ps-3 text-body-secondary py-3">No member yet.</td></tr>
                            @endforelse
                            @foreach ($invitations as $invitation)
                                <tr>
                                    <td class="ps-3">{{ $invitation->email }} <span class="badge text-bg-info ms-1">Invited</span></td>
                                    <td>{{ ucfirst($invitation->role->value) }}</td>
                                    <td class="pe-3 small text-body-secondary text-end">Until {{ $invitation->expires_at->toDisplay()->format('d/m/Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-body-secondary">
                    Add or remove members from <a href="{{ route('admin.users.index', ['team' => $team->id]) }}">Users</a>, or invite someone from there.
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <form method="POST" action="{{ route('admin.teams.update', $team) }}" class="card mb-4" novalidate>
                @csrf
                @method('PATCH')
                <div class="card-header py-3 fw-semibold"><i class="bi bi-pencil me-1"></i>Rename</div>
                <div class="card-body">
                    <label for="rename" class="form-label">Name</label>
                    <input id="rename" type="text" name="name" value="{{ old('name', $team->name) }}" maxlength="100"
                           class="form-control mb-3 @error('name') is-invalid @enderror" required>
                    @error('name')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                    <button type="submit" class="btn btn-outline-primary btn-sm">Rename</button>
                    <p class="form-text mb-0 mt-2">The Zabbix host group and the Grafana organization are renamed too.</p>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.teams.destroy', $team) }}" class="card border-danger-subtle" novalidate>
                @csrf
                @method('DELETE')
                <div class="card-header py-3 fw-semibold text-danger"><i class="bi bi-trash me-1"></i>Delete the team</div>
                <div class="card-body small">
                    <p>
                        Its <strong>{{ $team->monitoring_targets_count }} target(s)</strong> are removed from Zabbix with their alerts, and its
                        Grafana organization is deleted. Members keep their accounts. This cannot be undone.
                    </p>
                    <label for="confirm-name" class="form-label">Type <strong class="user-select-all">{{ $team->name }}</strong> to confirm</label>
                    <input id="confirm-name" type="text" name="confirm_name" autocomplete="off"
                           class="form-control mb-3 @error('confirm_name') is-invalid @enderror" required>
                    @error('confirm_name')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete permanently</button>
                </div>
            </form>
        </div>
    </div>
@endsection
