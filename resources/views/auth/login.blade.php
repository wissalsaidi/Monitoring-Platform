@extends('layouts.guest')

@section('title', 'Log in')

@section('content')
    <h2 class="h5 mb-4">Log in to your account</h2>

    @if (session('status'))
        <div class="alert alert-success small" role="status"><i class="bi bi-check-circle me-1"></i>{{ session('status') }}</div>
    @endif

    {{-- SSO OpenID Connect (SCRUM-88) : bouton seulement si le fournisseur est configuré --}}
    @if (\App\Services\OidcClient::enabled())
        @error('sso')
            <div class="alert alert-danger small" role="alert"><i class="bi bi-exclamation-octagon me-1"></i>{{ $message }}</div>
        @enderror
        <a href="{{ route('oidc.redirect') }}" class="btn {{ \App\Services\OidcClient::required() ? 'btn-primary' : 'btn-outline-primary' }} w-100">
            <i class="bi bi-building-lock me-1"></i> {{ config('oidc.button_label') }}
        </a>
        @unless (\App\Services\OidcClient::required())
            <div class="d-flex align-items-center gap-2 my-4 text-body-secondary small">
                <hr class="flex-grow-1 my-0"><span>or with your password</span><hr class="flex-grow-1 my-0">
            </div>
        @endunless
    @endif

    {{-- SSO obligatoire (SCRUM-90) : seul le bouton SSO reste --}}
    @if (\App\Services\OidcClient::required())
        <p class="text-center text-body-secondary small mt-4 mb-0">Sign in with your company account. Ask your administrator if you cannot.</p>
    @else
    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="current-password">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check mb-0">
                <input id="remember" type="checkbox" name="remember" class="form-check-input" @checked(old('remember'))>
                <label for="remember" class="form-check-label">Remember me</label>
            </div>
            <a href="{{ route('password.request') }}" class="small">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-box-arrow-in-right me-1"></i> Log in
        </button>
    </form>

    <p class="text-center text-body-secondary small mt-4 mb-0">
        @if (\App\Enums\RegistrationMode::current()->allowsSignUp())
            No account yet? <a href="{{ route('register') }}">Create one</a>
        @else
            Access is by invitation. Ask your administrator to invite you.
        @endif
    </p>
    @endif
@endsection
