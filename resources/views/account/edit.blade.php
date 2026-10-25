@extends('layouts.app')

@section('title', 'My account')

@section('content')
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body p-4">
                    <p class="section-label mb-3">Profile</p>
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-body-secondary fw-normal">Name</dt>
                        <dd class="col-sm-9">{{ $user->name }}</dd>
                        <dt class="col-sm-3 text-body-secondary fw-normal">Email</dt>
                        <dd class="col-sm-9">{{ $user->email }}</dd>
                        @if ($user->isSuperAdmin())
                            {{-- Rôle de plateforme (SCRUM-81) --}}
                            <dt class="col-sm-3 text-body-secondary fw-normal">Platform</dt>
                            <dd class="col-sm-9"><span class="badge text-bg-primary"><i class="bi bi-shield-lock me-1"></i>{{ $user->platform_role->label() }}</span></dd>
                        @endif
                        <dt class="col-sm-3 text-body-secondary fw-normal">Teams</dt>
                        <dd class="col-sm-9 mb-0">
                            @foreach ($teams as $team)
                                <div>{{ $team->name }} <span class="text-body-secondary small">· {{ ucfirst($team->pivot->role->value) }} · {{ $team->members_count }} {{ Str::plural('member', $team->members_count) }}</span></div>
                            @endforeach
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            {{-- Droit à l'effacement (RGPD-5, SCRUM-63) --}}
            <div class="card border-danger-subtle">
                <div class="card-body p-4">
                    <p class="section-label text-danger mb-3">Delete my account</p>

                    @if ($lastSuperAdmin)
                        <div class="alert alert-warning small d-flex gap-2" role="status">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span>You are the last Super Admin of the platform. Promote someone else first, then you can delete your account.</span>
                        </div>
                    @elseif ($blockingTeams)
                        <div class="alert alert-warning small d-flex gap-2" role="status">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span>You are the last Admin of {{ implode(', ', $blockingTeams) }}. Give the Admin role to another member, or remove the other members, before deleting your account.</span>
                        </div>
                    @else
                        <ul class="small text-body-secondary ps-3">
                            <li>Your account, your sessions and your team memberships are deleted.</li>
                            <li>Teams where you are the only member are deleted with their targets and their Zabbix monitoring.</li>
                            <li>In the other teams, targets stay; they no longer show who created them.</li>
                            <li>Audit log entries are kept without your name, email or IP address.</li>
                        </ul>
                        <p class="small fw-semibold">This cannot be undone.</p>

                        <form method="POST" action="{{ route('account.destroy') }}" data-confirm="Delete your account permanently?" novalidate>
                            @csrf
                            @method('DELETE')
                            <div class="mb-3">
                                <label for="password" class="form-label">Confirm with your password</label>
                                <input id="password" type="password" name="password" autocomplete="current-password" required
                                       class="form-control @error('password', 'deleteAccount') is-invalid @enderror">
                                @error('password', 'deleteAccount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-danger w-100"><i class="bi bi-trash me-1"></i>Delete my account</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
