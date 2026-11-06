@extends('layouts.app')

@section('title', $user->name)

@section('actions')
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>All users</a>
@endsection

@section('content')
    @include('admin._tabs')
    @php $isMe = $user->is(auth()->user()); @endphp

    <div class="row g-4">
        <div class="col-xl-8">
            {{-- Identité et statut --}}
            <div class="card mb-4">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <div class="me-auto">
                        <div class="h5 mb-1">
                            {{ $user->name }}
                            @if ($user->isSuperAdmin())<span class="badge text-bg-primary ms-1"><i class="bi bi-shield-lock me-1"></i>Super Admin</span>@endif
                            @if ($isMe)<span class="badge text-bg-secondary ms-1">You</span>@endif
                        </div>
                        <div class="text-body-secondary">{{ $user->email }}</div>
                    </div>
                    @if ($user->isActive())
                        <span class="badge text-bg-success fs-6">Active</span>
                    @else
                        <span class="badge text-bg-danger fs-6">Deactivated since {{ $user->deactivated_at->toDisplay()->format('d/m/Y H:i') }}</span>
                    @endif
                </div>
                <div class="card-footer small text-body-secondary d-flex flex-wrap gap-4">
                    <span><i class="bi bi-calendar me-1"></i>Created {{ $user->created_at->toDisplay()->format('d/m/Y') }}</span>
                    <span><i class="bi bi-box-arrow-in-right me-1"></i>Last login {{ $user->last_login_at?->toDisplay()->format('d/m/Y H:i') ?? 'never' }}</span>
                    <span><i class="bi bi-envelope-check me-1"></i>Email {{ $user->hasVerifiedEmail() ? 'verified' : 'not verified' }}</span>
                    <span><i class="bi bi-phone me-1"></i>2FA {{ filled($user->getAttributes()['two_factor_confirmed_at'] ?? null) ? 'on' : 'off' }}</span>
                </div>
            </div>

            {{-- Teams et rôles --}}
            <div class="card mb-4">
                <div class="card-header py-3 fw-semibold">Teams and roles <span class="badge text-bg-secondary ms-1">{{ $user->teams->count() }}</span></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <tbody>
                            @forelse ($user->teams as $team)
                                @php $sole = $soleAdminOf->contains($team->id); @endphp
                                <tr>
                                    <td class="ps-3 fw-semibold">{{ $teamLabels[$team->id] }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.users.teams.update', [$user, $team]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <select name="role" class="form-select form-select-sm w-auto" aria-label="Role in {{ $team->name }}" data-auto-submit
                                                    @disabled($sole) @if ($sole) title="Only Admin of the team" @endif>
                                                @foreach ($roles as $role)
                                                    <option value="{{ $role->value }}" @selected($team->pivot->role === $role)>{{ ucfirst($role->value) }}</option>
                                                @endforeach
                                            </select>
                                        </form>
                                    </td>
                                    <td class="pe-3 text-end">
                                        @if ($sole)
                                            <span class="small text-body-secondary">Only Admin of the team</span>
                                        @else
                                            <form method="POST" action="{{ route('admin.users.teams.destroy', [$user, $team]) }}" data-confirm="Remove {{ $user->name }} from {{ $team->name }}?">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-person-dash me-1"></i>Remove</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="ps-3 text-body-secondary py-3">No team: this person sees the "Waiting for access" page.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($otherTeams->isNotEmpty())
                    <div class="card-footer">
                        <form method="POST" action="{{ route('admin.users.teams.store', $user) }}" class="row g-2 align-items-center">
                            @csrf
                            <div class="col-sm"><select name="team_id" class="form-select form-select-sm" aria-label="Team to add to">
                                @foreach ($otherTeams as $team)<option value="{{ $team->id }}">{{ $teamLabels[$team->id] }}</option>@endforeach
                            </select></div>
                            <div class="col-sm-auto"><select name="role" class="form-select form-select-sm" aria-label="Role">
                                @foreach ($roles as $role)<option value="{{ $role->value }}" @selected($role === \App\Enums\TeamRole::Viewer)>{{ ucfirst($role->value) }}</option>@endforeach
                            </select></div>
                            <div class="col-sm-auto"><button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Add to team</button></div>
                        </form>
                    </div>
                @endif
            </div>

            @if ($pendingInvitations->isNotEmpty())
                <div class="alert alert-info small">
                    <i class="bi bi-envelope me-1"></i>Pending invitation{{ $pendingInvitations->count() > 1 ? 's' : '' }}:
                    {{ $pendingInvitations->map(fn ($i) => $i->team->name.' ('.ucfirst($i->role->value).')')->join(', ') }}
                </div>
            @endif

            {{-- Activité récente --}}
            <div class="card">
                <div class="card-header py-3 fw-semibold">Recent activity</div>
                <ul class="list-group list-group-flush small">
                    @forelse ($activity as $log)
                        <li class="list-group-item d-flex gap-3">
                            <span class="text-body-secondary text-nowrap">{{ $log->created_at->toDisplay()->format('d/m/Y H:i') }}</span>
                            <span>{{ $log->actionLabel() }} · {{ $log->subjectLabel() }}@if ($log->user && ! $log->user->is($user)) <span class="text-body-secondary">by {{ $log->user->name }}</span>@endif</span>
                        </li>
                    @empty
                        <li class="list-group-item text-body-secondary">Nothing recorded yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- Actions sur le compte --}}
        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header py-3 fw-semibold"><i class="bi bi-shield-lock me-1"></i>Super Admin</div>
                <div class="card-body small">
                    @if ($user->isSuperAdmin())
                        <p class="mb-3">Manages users, teams and the platform audit. No rights inside a team without being a member.</p>
                        @if ($lastSuperAdmin)
                            <p class="text-body-secondary mb-0">The last active Super Admin cannot lose the role.</p>
                        @else
                            <form method="POST" action="{{ route('admin.users.super-admin.destroy', $user) }}" data-confirm="Remove the Super Admin role from {{ $user->name }}?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger btn-sm">Remove Super Admin</button>
                            </form>
                        @endif
                    @else
                        <p class="mb-3">Give this person access to the administration console.</p>
                        <form method="POST" action="{{ route('admin.users.super-admin.store', $user) }}" data-confirm="Make {{ $user->name }} a Super Admin? They will manage every user and team.">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary btn-sm" @disabled(! $user->isActive())>Make Super Admin</button>
                        </form>
                    @endif
                </div>
            </div>

            @unless ($isMe)
                <div class="card mb-4">
                    <div class="card-header py-3 fw-semibold"><i class="bi bi-key me-1"></i>Password</div>
                    <div class="card-body small">
                        <p class="mb-3">The current password stops working, open sessions are closed and {{ $user->email }} receives a link to choose a new one.</p>
                        <form method="POST" action="{{ route('admin.users.password-reset', $user) }}" data-confirm="Force {{ $user->name }} to choose a new password?">
                            @csrf
                            <button type="submit" class="btn btn-outline-warning btn-sm" @disabled(! $user->isActive())>Force a password reset</button>
                        </form>
                    </div>
                </div>

                <div class="card border-danger-subtle">
                    <div class="card-header py-3 fw-semibold"><i class="bi bi-person-slash me-1"></i>Access</div>
                    <div class="card-body small">
                        @if ($user->isActive())
                            <p class="mb-3">Login refused, sessions closed, Grafana access removed and no more notifications. Teams, history and audit are kept, and the account can be reactivated.</p>
                            <form method="POST" action="{{ route('admin.users.deactivate', $user) }}" data-confirm="Deactivate {{ $user->name }}? They are logged out immediately.">
                                @csrf
                                <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-person-slash me-1"></i>Deactivate the account</button>
                            </form>
                        @else
                            <p class="mb-3">The account gets its teams and roles back. Grafana access is restored.</p>
                            <form method="POST" action="{{ route('admin.users.reactivate', $user) }}">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-person-check me-1"></i>Reactivate the account</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endunless
        </div>
    </div>
@endsection
