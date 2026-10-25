@extends('layouts.guest')

@section('title', 'Create an account')

@section('content')
    <h2 class="h5 mb-4">Create your account</h2>

    <form method="POST" action="{{ route('register') }}" novalidate>
        @csrf

        <div class="mb-3">
            <label for="name" class="form-label">Name</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}"
                   class="form-control @error('name') is-invalid @enderror"
                   required autofocus autocomplete="name">
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            @if ($domains)
                <div class="form-text">Use your company address: {{ collect($domains)->map(fn ($d) => '@'.$d)->join(', ') }}.</div>
            @endif
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="new-password">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="form-control" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-person-plus me-1"></i> Create account
        </button>
    </form>

    <p class="text-body-secondary small mt-3 mb-0">
        <i class="bi bi-info-circle me-1"></i>After you verify your email, an administrator adds you to a team. Until then, you will not see any data.
    </p>

    <p class="text-center text-body-secondary small mt-4 mb-0">
        Already registered? <a href="{{ route('login') }}">Log in</a>
    </p>
@endsection
