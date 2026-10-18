@extends('layouts.app')

@section('title', 'Team members')

@section('actions')
    @if ($teams->count() > 1)
        {{-- Plusieurs teams administrées : choix de la team --}}
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-people me-1"></i>{{ $team->name }}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                @foreach ($teams as $other)
                    <li><a class="dropdown-item @if ($other->is($team)) active @endif" href="{{ route('teams.members.index', $other) }}">{{ $other->name }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection

@section('content')
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex align-items-center py-3">
                    <span class="fw-semibold">{{ $team->name }}</span>
                    <span class="badge text-bg-secondary ms-2">{{ $members->count() }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">Member</th>
                                <th>Role</th>
                                <th class="d-none d-md-table-cell">Joined</th>
                                <th class="pe-3"><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($members as $member)
                                @php
                                    // Le dernier Admin ne peut être ni rétrogradé ni retiré
                                    $lastAdmin = $member->pivot->role === \App\Enums\TeamRole::Admin && $adminCount === 1;
                                    $isMe = $member->is(auth()->user());
                                @endphp
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-semibold">{{ $member->name }} @if ($isMe)<span class="badge text-bg-secondary ms-1">You</span>@endif</div>
                                        <div class="small text-body-secondary">{{ $member->email }}</div>
                                    </td>
                                    <td>
                                        @can('user.update', $team)
                                            <form method="POST" action="{{ route('teams.members.update', [$team, $member]) }}">
                                                @csrf
                                                @method('PATCH')
                                                <select name="role" class="form-select form-select-sm w-auto" aria-label="Role of {{ $member->name }}"
                                                        data-auto-submit @disabled($lastAdmin)
                                                        @if ($lastAdmin) title="A team must keep at least one Admin" @endif>
                                                    @foreach ($roles as $role)
                                                        <option value="{{ $role->value }}" @selected($member->pivot->role === $role)>{{ ucfirst($role->value) }}</option>
                                                    @endforeach
                                                </select>
                                            </form>
                                        @else
                                            {{ ucfirst($member->pivot->role->value) }}
                                        @endcan
                                    </td>
                                    <td class="d-none d-md-table-cell small text-body-secondary">{{ $member->pivot->created_at?->toDisplay()->format('d/m/Y') }}</td>
                                    <td class="pe-3 text-end">
                                        @can('user.delete', $team)
                                            @unless ($lastAdmin)
                                                <form method="POST" action="{{ route('teams.members.destroy', [$team, $member]) }}"
                                                      data-confirm="{{ $isMe ? 'Leave the '.$team->name.' team?' : 'Remove '.$member->name.' from the team?' }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-outline-danger btn-sm text-nowrap">
                                                        <i class="bi bi-person-dash me-1"></i>{{ $isMe ? 'Leave' : 'Remove' }}
                                                    </button>
                                                </form>
                                            @endunless
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($invitations->isNotEmpty())
                <div class="card mt-4">
                    <div class="card-header d-flex align-items-center py-3">
                        <span class="fw-semibold">Pending invitations</span>
                        <span class="badge text-bg-secondary ms-2">{{ $invitations->count() }}</span>
                    </div>
                    <ul class="list-group list-group-flush">
                        @foreach ($invitations as $invitation)
                            <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                                <div class="me-auto">
                                    <div class="fw-semibold">{{ $invitation->email }}</div>
                                    <div class="small text-body-secondary">
                                        {{ ucfirst($invitation->role->value) }} · invited by {{ $invitation->inviter?->name ?? 'a deleted user' }} ·
                                        @if ($invitation->isExpired())
                                            <span class="text-warning">expired</span>
                                        @else
                                            expires {{ $invitation->expires_at->toDisplay()->format('d/m/Y H:i') }}
                                        @endif
                                    </div>
                                </div>
                                @can('user.create', $team)
                                    <form method="POST" action="{{ route('teams.invitations.store', $team) }}">
                                        @csrf
                                        <input type="hidden" name="email" value="{{ $invitation->email }}">
                                        <input type="hidden" name="role" value="{{ $invitation->role->value }}">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-send me-1"></i>Resend</button>
                                    </form>
                                    <form method="POST" action="{{ route('teams.invitations.destroy', [$team, $invitation]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Cancel</button>
                                    </form>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        @can('user.create', $team)
            <div class="col-xl-4">
                <form method="POST" action="{{ route('teams.invitations.store', $team) }}" class="card" novalidate>
                    @csrf
                    <div class="card-body p-4">
                        <p class="section-label mb-3">Invite a member</p>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input id="email" type="email" name="email" value="{{ old('email') }}" maxlength="255" required
                                   class="form-control @error('email') is-invalid @enderror" placeholder="name@company.com">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select id="role" name="role" class="form-select @error('role') is-invalid @enderror">
                                @foreach ($roles as $role)
                                    <option value="{{ $role->value }}" @selected(old('role', 'viewer') === $role->value)>{{ ucfirst($role->value) }}</option>
                                @endforeach
                            </select>
                            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Viewer: read only. Manager: also manages targets and acknowledges alerts. Admin: also manages members.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send me-1"></i>Send invitation</button>
                        <p class="small text-body-secondary mt-3 mb-0">The link in the email is valid for {{ \App\Models\TeamInvitation::VALID_DAYS }} days.</p>
                    </div>
                </form>
            </div>
        @endcan
    </div>
@endsection
