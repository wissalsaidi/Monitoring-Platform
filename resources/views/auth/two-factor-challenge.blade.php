@extends('layouts.guest')

@section('title', 'Two-factor authentication')

@section('content')
    <div class="text-center mb-3">
        <i class="bi bi-phone text-primary" style="font-size: 2.5rem"></i>
    </div>
    <h2 class="h5 mb-2 text-center">Two-factor authentication</h2>
    <p class="text-body-secondary small mb-4 text-center">Enter the 6-digit code shown in your authenticator app.</p>

    <form method="POST" action="{{ route('two-factor.login.store') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label for="code" class="form-label">Code</label>
            <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                   class="form-control form-control-lg text-center @error('code') is-invalid @enderror"
                   autocomplete="one-time-code" autofocus>
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-shield-check me-1"></i>Verify</button>
    </form>

    {{-- Sans JavaScript (CSP) : le code de secours est dans un bloc dépliable --}}
    <details class="mt-4 small" @if ($errors->has('recovery_code')) open @endif>
        <summary class="text-body-secondary">Lost your phone? Use a recovery code</summary>
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-3" novalidate>
            @csrf
            <label for="recovery_code" class="form-label">Recovery code</label>
            <input id="recovery_code" type="text" name="recovery_code" autocomplete="off"
                   class="form-control mb-2 @error('recovery_code') is-invalid @enderror">
            @error('recovery_code')<div class="invalid-feedback mb-2">{{ $message }}</div>@enderror
            <button type="submit" class="btn btn-outline-secondary btn-sm">Use the recovery code</button>
            <p class="text-body-secondary mt-2 mb-0">Each code works once. No code left? Ask a Super Admin to reset your two-factor authentication.</p>
        </form>
    </details>
@endsection
