@extends('layouts.guest')

@section('title', 'Forgot password')

@section('content')
    <h2 class="h5 mb-2">Forgot your password?</h2>
    <p class="text-body-secondary small mb-4">Enter your email and we will send you a link to choose a new one. The link is valid for 60 minutes.</p>

    @if (session('status'))
        <div class="alert alert-success small" role="status"><i class="bi bi-envelope-check me-1"></i>{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        <div class="mb-4">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-envelope me-1"></i> Email me a reset link
        </button>
    </form>

    <p class="text-center text-body-secondary small mt-4 mb-0">
        <a href="{{ route('login') }}"><i class="bi bi-arrow-left me-1"></i>Back to log in</a>
    </p>
@endsection
