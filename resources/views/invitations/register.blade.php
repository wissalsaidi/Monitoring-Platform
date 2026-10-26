@extends('layouts.guest')

@section('title', 'Create your account')

@section('content')
    <h2 class="h5 mb-1">Create your account</h2>
    <p class="text-body-secondary small mb-4">
        {{ $invitation->inviter?->name ?? 'An administrator' }} invited you to join the
        <strong class="text-body">{{ $invitation->team->name }}</strong> team as
        <strong class="text-body">{{ ucfirst($invitation->role->value) }}</strong>.
    </p>

    <form method="POST" action="{{ $action }}" novalidate>
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" value="{{ $invitation->email }}" class="form-control" readonly disabled autocomplete="username">
            <div class="form-text">The address the invitation was sent to. It is already verified.</div>
        </div>

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
            <i class="bi bi-person-check me-1"></i> Create account and join {{ $invitation->team->name }}
        </button>
    </form>

    <p class="text-center text-body-secondary small mt-4 mb-0">
        Already have an account? <a href="{{ route('login') }}">Log in</a>
    </p>
@endsection
