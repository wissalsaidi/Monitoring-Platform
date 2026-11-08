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
                        {{-- Moyens de connexion (SCRUM-89) --}}
                        <dt class="col-sm-3 text-body-secondary fw-normal">Sign-in</dt>
                        <dd class="col-sm-9">
                            @if ($user->hasSso())
                                <span class="badge text-bg-primary"><i class="bi bi-building-lock me-1"></i>Single sign-on</span>
                                <span class="small text-body-secondary ms-1">{{ $ssoProvider }}</span>
                            @endif
                            @if ($user->hasPassword())
                                <span class="badge text-bg-light border"><i class="bi bi-key me-1"></i>Password</span>
                            @else
                                <div class="small text-body-secondary mt-1">No password on this platform: you sign in with your company account.</div>
                            @endif
                        </dd>
                        <dt class="col-sm-3 text-body-secondary fw-normal">2FA</dt>
                        <dd class="col-sm-9">
                            @if ($user->hasEnabledTwoFactorAuthentication())
                                <span class="badge text-bg-success"><i class="bi bi-shield-check me-1"></i>On</span>
                            @else
                                <span class="badge text-bg-secondary">Off</span>@if ($user->requiresTwoFactor())<span class="text-warning small ms-1">required for your role</span>@endif
                            @endif
                            <a href="{{ route('account.two-factor') }}" class="small ms-2">Manage</a>
                        </dd>
                        <dt class="col-sm-3 text-body-secondary fw-normal">Teams</dt>
                        <dd class="col-sm-9 mb-0">
                            @foreach ($teams as $team)
                                <div>{{ $team->name }} <span class="text-body-secondary small">· {{ ucfirst($team->pivot->role->value) }} · {{ $team->members_count }} {{ Str::plural('member', $team->members_count) }}</span></div>
                            @endforeach
                        </dd>
                    </dl>
                </div>
            </div>

            @if ($user->hasSso() && $user->hasPassword())
                {{-- Délier le SSO (SCRUM-89) : seulement s'il reste un mot de passe --}}
                <div class="card mt-4">
                    <div class="card-body p-4">
                        <p class="section-label mb-2">Single sign-on</p>
                        <p class="small text-body-secondary">Your account is linked to your company account at {{ $ssoProvider }}. Unlink it to sign in with your email and password only.</p>
                        <form method="POST" action="{{ route('account.sso.destroy') }}" class="row g-2 align-items-start" data-confirm="Unlink single sign-on from your account?" novalidate>
                            @csrf
                            @method('DELETE')
                            <div class="col-sm">
                                <label for="sso-password" class="visually-hidden">Your password</label>
                                <input id="sso-password" type="password" name="password" placeholder="Your password" autocomplete="current-password" required
                                       class="form-control @error('password', 'unlinkSso') is-invalid @enderror">
                                @error('password', 'unlinkSso')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-sm-auto">
                                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-link-45deg me-1"></i>Unlink</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
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
                            @if ($user->hasPassword())
                                <div class="mb-3">
                                    <label for="password" class="form-label">Confirm with your password</label>
                                    <input id="password" type="password" name="password" autocomplete="current-password" required
                                           class="form-control @error('password', 'deleteAccount') is-invalid @enderror">
                                    @error('password', 'deleteAccount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            @endif
                            @if ($user->hasPassword() || $identityConfirmed)
                                <button type="submit" class="btn btn-danger w-100"><i class="bi bi-trash me-1"></i>Delete my account</button>
                            @endif
                        </form>
                        @if (! $user->hasPassword() && ! $identityConfirmed)
                            {{-- Compte SSO sans mot de passe (SCRUM-89) : reconnexion chez le fournisseur avant la suppression --}}
                            <a href="{{ route('oidc.reauth') }}" class="btn btn-outline-danger w-100"><i class="bi bi-building-lock me-1"></i>Confirm with single sign-on to delete</a>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
