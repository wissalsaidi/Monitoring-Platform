@extends('layouts.app')

@section('title', 'Two-factor authentication')

@section('actions')
    <a href="{{ route('account.edit') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>My account</a>
@endsection

@section('content')
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <p class="section-label mb-0 me-auto">Authenticator app</p>
                        @if ($enabled)
                            <span class="badge text-bg-success"><i class="bi bi-shield-check me-1"></i>On</span>
                        @elseif ($pending)
                            <span class="badge text-bg-warning">Waiting for the first code</span>
                        @else
                            <span class="badge text-bg-secondary">Off</span>
                        @endif
                    </div>

                    @if ($required && ! $enabled)
                        <div class="alert alert-warning small d-flex gap-2" role="status">
                            <i class="bi bi-shield-exclamation"></i>
                            <span>As {{ $user->isSuperAdmin() ? 'Super Admin' : 'a team Admin' }}, you must use two-factor authentication. The other pages open once it is on.</span>
                        </div>
                    @endif

                    @if ($enabled)
                        <p class="small">At each login, after your password, you enter the 6-digit code shown in your authenticator app.</p>

                        @if ($showRecoveryCodes)
                            <div class="alert alert-info small" role="status">
                                <p class="fw-semibold mb-2"><i class="bi bi-key me-1"></i>Your recovery codes</p>
                                <p class="mb-2">Store them somewhere safe (password manager). Each one lets you log in once without your phone. They will not be shown again.</p>
                                <div class="row row-cols-2 g-1 font-monospace user-select-all">
                                    @foreach ($user->recoveryCodes() as $code)
                                        <div class="col">{{ $code }}</div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="d-flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" data-confirm="Generate new recovery codes? The current ones stop working.">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>New recovery codes</button>
                            </form>
                            @unless ($required)
                                <form method="POST" action="{{ route('two-factor.disable') }}" data-confirm="Turn off two-factor authentication?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Turn off</button>
                                </form>
                            @endunless
                        </div>
                    @elseif ($pending)
                        <ol class="small ps-3">
                            <li class="mb-2">Open your authenticator app (Google Authenticator, Microsoft Authenticator…) and scan this QR code, or type the key.</li>
                            <li>Enter the 6-digit code the app shows.</li>
                        </ol>
                        @if ((time() - (int) session('auth.password_confirmed_at', 0)) < (int) config('auth.password_timeout'))
                            <div class="d-flex flex-wrap align-items-center gap-4 mb-3">
                                <div class="bg-white p-2 rounded">{!! $user->twoFactorQrCodeSvg() !!}</div>
                                <div class="small">
                                    <div class="text-body-secondary">Key</div>
                                    <code class="user-select-all">{{ decrypt($user->two_factor_secret) }}</code>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('two-factor.confirm') }}" class="d-flex gap-2 align-items-start" novalidate>
                                @csrf
                                <div>
                                    <input type="text" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" aria-label="Code"
                                           class="form-control @error('code', 'confirmTwoFactorAuthentication') is-invalid @enderror" placeholder="123456" autofocus>
                                    @error('code', 'confirmTwoFactorAuthentication')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <button type="submit" class="btn btn-primary">Confirm</button>
                            </form>
                        @else
                            <a href="{{ route('password.confirm') }}" class="btn btn-outline-primary btn-sm">Confirm your password to see the QR code</a>
                        @endif
                    @else
                        <p class="small">After your password, you will also enter a 6-digit code from an app on your phone. A stolen password is then not enough to enter your account.</p>
                        <form method="POST" action="{{ route('account.two-factor.enable') }}" class="row g-2 align-items-start" novalidate>
                            @csrf
                            <div class="col-sm">
                                <label for="password" class="visually-hidden">Your password</label>
                                <input id="password" type="password" name="password" placeholder="Your password" autocomplete="current-password"
                                       class="form-control @error('password') is-invalid @enderror" required>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-sm-auto">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-shield-lock me-1"></i>Set up two-factor authentication</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-body p-4 small">
                    <p class="section-label mb-3">Good to know</p>
                    <ul class="ps-3 mb-0">
                        <li class="mb-2">Works with any TOTP app: Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden…</li>
                        <li class="mb-2">Lost your phone? Log in with a recovery code, then generate new codes.</li>
                        <li>No recovery code left? A Super Admin can reset your two-factor authentication.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
