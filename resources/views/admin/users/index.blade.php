@extends('layouts.app')

@section('title', 'Users')

@section('content')
    @php
        $filterUrl = fn (array $change) => route('admin.users.index', array_filter([...request()->only(['q', 'status', 'team']), ...$change], fn ($v) => $v !== null && $v !== ''));
    @endphp

    <div class="row g-4">
        <div class="col-xxl-9">
            <div class="card">
                <div class="card-header py-3">
                    <ul class="nav nav-pills small gap-1 mb-3">
                        <li class="nav-item"><a class="nav-link py-1 @if (! $status) active @endif" href="{{ $filterUrl(['status' => null]) }}">All <span class="badge text-bg-secondary ms-1">{{ $counts['all'] }}</span></a></li>
                        @foreach (\App\Http\Controllers\Admin\UserController::STATUSES as $value => $label)
                            <li class="nav-item"><a class="nav-link py-1 @if ($status === $value) active @endif" href="{{ $filterUrl(['status' => $value]) }}">{{ $label }} <span class="badge text-bg-secondary ms-1">{{ $counts[$value] }}</span></a></li>
                        @endforeach
                    </ul>
                    <form method="GET" action="{{ route('admin.users.index') }}" class="row g-2 align-items-center" role="search">
                        @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                        <div class="col-sm">
                            <input type="search" name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="Search by name or email" aria-label="Search by name or email">
                        </div>
                        <div class="col-sm-auto">
                            <select name="team" class="form-select form-select-sm" aria-label="Team" data-auto-submit>
                                <option value="">All teams</option>
                                @foreach ($teams as $team)
                                    <option value="{{ $team->id }}" @selected((int) $teamId === $team->id)>{{ $teamLabels[$team->id] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-auto">
                            <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-search me-1"></i>Search</button>
                            @if ($q !== '' || $teamId)
                                <a href="{{ $filterUrl(['q' => null, 'team' => null]) }}" class="btn btn-link btn-sm">Clear</a>
                            @endif
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    @if ($invitations)
                        {{-- Invités : invitation en attente, pas encore de compte --}}
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-3">Email</th>
                                    <th>Team and role</th>
                                    <th class="d-none d-md-table-cell">Invited by</th>
                                    <th class="pe-3">Expires</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($invitations as $invitation)
                                    <tr>
                                        <td class="ps-3">{{ $invitation->email }} <span class="badge text-bg-info ms-1">Invited</span></td>
                                        <td>{{ $teamLabels[$invitation->team_id] }} · {{ ucfirst($invitation->role->value) }}</td>
                                        <td class="d-none d-md-table-cell small text-body-secondary">{{ $invitation->inviter?->name ?? 'Console' }}</td>
                                        <td class="pe-3 small @if ($invitation->isExpired()) text-danger @else text-body-secondary @endif">
                                            {{ $invitation->expires_at->toDisplay()->format('d/m/Y H:i') }}@if ($invitation->isExpired()) · expired @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-body-secondary py-4">No pending invitation.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @else
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-3">User</th>
                                    <th>Teams and roles</th>
                                    <th>Status</th>
                                    <th class="d-none d-lg-table-cell">Last login</th>
                                    <th class="d-none d-lg-table-cell pe-3">2FA</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($users as $user)
                                    <tr>
                                        <td class="ps-3">
                                            <a href="{{ route('admin.users.show', $user) }}" class="fw-semibold text-decoration-none">{{ $user->name }}</a>
                                            @if ($user->isSuperAdmin())<span class="badge text-bg-primary ms-1"><i class="bi bi-shield-lock me-1"></i>Super Admin</span>@endif
                                            @if ($user->is(auth()->user()))<span class="badge text-bg-secondary ms-1">You</span>@endif
                                            <div class="small text-body-secondary">{{ $user->email }}</div>
                                        </td>
                                        <td class="small">
                                            @forelse ($user->teams as $team)
                                                <span class="badge text-bg-light border me-1">{{ $teamLabels[$team->id] }} · {{ ucfirst($team->pivot->role->value) }}</span>
                                            @empty
                                                <span class="text-body-secondary">No team</span>
                                            @endforelse
                                        </td>
                                        <td>
                                            @if ($user->isActive())
                                                <span class="badge text-bg-success">Active</span>
                                            @else
                                                <span class="badge text-bg-danger" title="Since {{ $user->deactivated_at->toDisplay()->format('d/m/Y H:i') }}">Deactivated</span>
                                            @endif
                                        </td>
                                        <td class="d-none d-lg-table-cell small text-body-secondary">{{ $user->last_login_at?->toDisplay()->format('d/m/Y H:i') ?? 'Never' }}</td>
                                        <td class="d-none d-lg-table-cell pe-3 small">
                                            @if (filled($user->getAttributes()['two_factor_confirmed_at'] ?? null))
                                                <i class="bi bi-check-circle text-success"></i> On
                                            @else
                                                <span class="text-body-secondary">Off</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-body-secondary py-4">No user matches these filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @endif
                </div>
                @php $page = $invitations ?? $users; @endphp
                @if ($page->hasPages())
                    <div class="card-footer pt-3 small">{{ $page->onEachSide(1)->links() }}</div>
                @endif
            </div>
        </div>

        <div class="col-xxl-3">
            <form method="POST" action="{{ route('admin.users.invite') }}" class="card" novalidate>
                @csrf
                <div class="card-header py-3 fw-semibold"><i class="bi bi-envelope-plus me-1"></i>Invite someone</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="invite-email" class="form-label">Email</label>
                        <input id="invite-email" type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="invite-team" class="form-label">Team</label>
                        <select id="invite-team" name="team_id" class="form-select @error('team_id') is-invalid @enderror" required>
                            @foreach ($teams as $team)
                                <option value="{{ $team->id }}" @selected((int) old('team_id') === $team->id)>{{ $teamLabels[$team->id] }}</option>
                            @endforeach
                        </select>
                        @error('team_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="invite-role" class="form-label">Role</label>
                        <select id="invite-role" name="role" class="form-select" required>
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(old('role', 'viewer') === $role->value)>{{ ucfirst($role->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" @disabled($teams->isEmpty())><i class="bi bi-send me-1"></i>Send invitation</button>
                    <p class="form-text mb-0 mt-2">The link is valid for 7 days. Without an account, the person creates one from the link.</p>
                </div>
            </form>
        </div>
    </div>
@endsection
