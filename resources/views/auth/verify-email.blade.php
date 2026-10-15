@extends('layouts.guest')

@section('title', 'Verify your email')

@section('content')
    <div class="text-center mb-3">
        <i class="bi bi-envelope-paper text-primary" style="font-size: 2.5rem"></i>
    </div>
    <h2 class="h5 mb-2 text-center">Verify your email</h2>
    <p class="text-body-secondary small mb-4 text-center">
        We sent a verification link to <strong class="text-body">{{ auth()->user()->email }}</strong>.
        Click it to access the dashboard. The link is valid for 60 minutes.
    </p>

    @if (session('status') === 'verification-link-sent')
        <div class="alert alert-success small" role="status"><i class="bi bi-envelope-check me-1"></i>A new verification link has been sent.</div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-arrow-repeat me-1"></i> Resend the verification email
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="text-center mt-3">
        @csrf
        <button type="submit" class="btn btn-link btn-sm text-body-secondary">Log out</button>
    </form>
@endsection
