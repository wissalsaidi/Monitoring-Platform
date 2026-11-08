@extends('layouts.guest')

@section('title', 'Confirm your password')

@section('content')
    @if (auth()->user()->hasPassword())
        <h2 class="h5 mb-2">Confirm your password</h2>
        <p class="text-body-secondary small mb-4">This is a sensitive action. Enter your password to continue.</p>

        <form method="POST" action="{{ route('password.confirm.store') }}" novalidate>
            @csrf
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <input id="password" type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                       required autofocus autocomplete="current-password">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary w-100">Confirm</button>
        </form>
    @else
        {{-- Compte SSO sans mot de passe (SCRUM-89) : reconnexion chez le fournisseur d'identité --}}
        <h2 class="h5 mb-2">Confirm your identity</h2>
        <p class="text-body-secondary small mb-4">This is a sensitive action. Sign in again with your company account to continue.</p>
        @if (\App\Services\OidcClient::enabled() && auth()->user()->hasSso())
            <a href="{{ route('oidc.reauth') }}" class="btn btn-primary w-100"><i class="bi bi-building-lock me-1"></i>Confirm with single sign-on</a>
        @else
            <div class="alert alert-warning small mb-0" role="status">
                Your account has no password and single sign-on is not available. Use "Forgot your password?" on the login page to choose one.
            </div>
        @endif
    @endif

    <p class="text-center small mt-4 mb-0"><a href="{{ route('account.two-factor') }}" class="text-body-secondary">Cancel</a></p>
@endsection
