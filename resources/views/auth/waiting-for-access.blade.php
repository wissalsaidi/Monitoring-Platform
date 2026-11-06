@extends('layouts.guest')

@section('title', 'Waiting for access')

@section('content')
    <div class="text-center mb-3">
        <i class="bi bi-hourglass-split text-primary" style="font-size: 2.5rem"></i>
    </div>
    <h2 class="h5 mb-2 text-center">Waiting for access</h2>
    <p class="text-body-secondary small mb-3 text-center">
        Your account <strong class="text-body">{{ auth()->user()->email }}</strong> is ready,
        but you are not a member of any team yet, so there is nothing to show.
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
                to add you to a team. Give them the address above.
            </li>
            <li>If you received an invitation email, open its link while logged in with this account.</li>
        </ul>
    </div>

    @can('isSuperAdmin')
        <div class="alert alert-info small" role="status">
            <i class="bi bi-shield-lock me-1"></i>You are a Super Admin. You have no rights inside a team until you are a member of it.
            <a href="{{ route('admin.users.index') }}" class="alert-link d-block mt-1">Open the administration console</a>
        </div>
    @endcan

    <div class="d-flex justify-content-between align-items-center">
        <a href="{{ route('account.edit') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-person-gear me-1"></i>My account
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-link btn-sm text-body-secondary">Log out</button>
        </form>
    </div>
@endsection
