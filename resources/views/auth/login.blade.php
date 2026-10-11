@extends('layouts.guest')

@section('title', 'Log in')

@section('content')
    <h2 class="h5 mb-4">Log in to your account</h2>

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

        <div class="form-check mb-4">
            <input id="remember" type="checkbox" name="remember" class="form-check-input" @checked(old('remember'))>
            <label for="remember" class="form-check-label">Remember me</label>
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-box-arrow-in-right me-1"></i> Log in
        </button>
    </form>

    <p class="text-center text-body-secondary small mt-4 mb-0">
        No account yet? <a href="{{ route('register') }}">Create one</a>
    </p>
@endsection
