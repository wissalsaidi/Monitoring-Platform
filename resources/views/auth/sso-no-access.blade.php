@extends('layouts.guest')

@section('title', 'Waiting for access')

@section('content')
    {{-- Connexion SSO d'une personne inconnue, inscription fermée (SCRUM-89) : aucun compte n'a été créé --}}
    <div class="text-center mb-3">
        <i class="bi bi-hourglass-split text-primary" style="font-size: 2.5rem"></i>
    </div>
    <h2 class="h5 mb-2 text-center">Waiting for access</h2>
    <p class="text-body-secondary small mb-3 text-center">
        Your company account <strong class="text-body">{{ $email }}</strong> is valid,
        but you do not have access to this platform yet. No account was created.
    </p>

    <div class="border rounded p-3 small mb-3">
        <p class="fw-semibold mb-2"><i class="bi bi-person-raised-hand me-1"></i>What to do</p>
        <ul class="mb-0 ps-3">
            <li class="mb-1">
                Ask
                @if ($contact)
                    <a href="mailto:{{ $contact }}">{{ $contact }}</a>
                @else
                    your administrator or the Admin of your team
                @endif
                to invite you. Give them the address above.
            </li>
            <li>Once invited, sign in with single sign-on again: you arrive directly in your team.</li>
        </ul>
    </div>

    <p class="text-center small mb-0"><a href="{{ route('login') }}" class="text-body-secondary">Back to login</a></p>
@endsection
