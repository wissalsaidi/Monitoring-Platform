@extends('layouts.app')

@section('title', 'Edit webhook')

@section('content')
    @include('teams._notification-tabs')

    <div class="row g-4">
        <div class="col-xl-6">
            <form method="POST" action="{{ route('teams.notifications.webhooks.update', [$team, $webhook]) }}" class="card">
                @csrf
                @method('PUT')
                <div class="card-header py-3">
                    <span class="fw-semibold">{{ $webhook->name }}</span>
                    <span class="ms-2 small text-body-secondary">{{ $team->name }}</span>
                </div>
                <div class="card-body">
                    @include('teams._webhook-form')
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Save</button>
                    <a href="{{ route('teams.notifications.webhooks.index', $team) }}" class="btn btn-outline-secondary btn-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
