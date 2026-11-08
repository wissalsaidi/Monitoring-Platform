@extends('layouts.guest')

@section('title', 'Join your team')

@section('content')
    {{-- SSO obligatoire (SCRUM-90) : le compte est créé à la première connexion SSO, qui accepte l'invitation --}}
    <h2 class="h5 mb-1">Join your team</h2>
    <p class="text-body-secondary small mb-4">
        {{ $invitation->inviter?->name ?? 'An administrator' }} invited you to join the
        <strong class="text-body">{{ $invitation->team->name }}</strong> team as
        <strong class="text-body">{{ ucfirst($invitation->role->value) }}</strong>.
        Sign in with your company account <strong class="text-body">{{ $invitation->email }}</strong>: your account is created and you join the team.
    </p>
    <a href="{{ route('oidc.redirect') }}" class="btn btn-primary w-100">
        <i class="bi bi-building-lock me-1"></i> {{ config('oidc.button_label') }}
    </a>
@endsection
