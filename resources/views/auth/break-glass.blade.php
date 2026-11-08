@extends('layouts.guest')

@section('title', 'Emergency access')

@section('content')
    {{-- Compte de secours (SCRUM-90) : seulement quand le SSO est obligatoire --}}
    <h2 class="h5 mb-2"><i class="bi bi-life-preserver text-danger me-1"></i>Emergency access</h2>
    <p class="text-body-secondary small mb-4">
        Only for the designated emergency Super Admin, when single sign-on is unavailable. Every login is recorded and
        the other Super Admins are notified by email.
    </p>

    <form method="POST" action="{{ route('break-glass.store') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="form-control @error('email') is-invalid @enderror">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-4">
            <label for="password" class="form-label">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password"
                   class="form-control @error('password') is-invalid @enderror">
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button type="submit" class="btn btn-danger w-100"><i class="bi bi-box-arrow-in-right me-1"></i>Log in</button>
    </form>

    <p class="text-center small mt-4 mb-0"><a href="{{ route('login') }}" class="text-body-secondary">Back to single sign-on</a></p>
@endsection
