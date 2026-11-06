@extends('layouts.app')

@section('title', 'Notifications')

@section('actions')
    @if ($teams->count() > 1)
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-people me-1"></i>{{ $team->name }}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                @foreach ($teams as $other)
                    <li><a class="dropdown-item @if ($other->is($team)) active @endif" href="{{ route('teams.notifications.history', $other) }}">{{ $other->name }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection

@section('content')
    @include('teams._notification-tabs')

    {{-- Filtres (SCRUM-56) en GET : URL partageable ; « alert » vient du lien d'une alerte --}}
    <form method="GET" action="{{ route('teams.notifications.history', $team) }}" class="row g-2 align-items-center mb-3" role="search">
        @if ($alert)
            <input type="hidden" name="alert" value="{{ $alert->id }}">
        @endif
        <div class="col-6 col-md-3">
            <select name="status" class="form-select form-select-sm" aria-label="Filter by status" data-auto-submit>
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-4">
            <select name="target" class="form-select form-select-sm" aria-label="Filter by target" data-auto-submit>
                <option value="">All targets</option>
                @foreach ($targets as $target)
                    <option value="{{ $target->id }}" @selected((int) ($filters['target'] ?? 0) === $target->id)>{{ $target->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto d-flex gap-2 align-items-center">
            <noscript><button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button></noscript>
            @if ($alert)
                <span class="badge text-bg-secondary fw-normal">
                    Alert: {{ $alert->target->name }} · {{ $alert->started_at->toDisplay()->format('d/m H:i') }}
                </span>
            @endif
            @if (array_filter($filters))
                <a href="{{ route('teams.notifications.history', $team) }}" class="btn btn-link btn-sm text-body-secondary">Reset</a>
            @endif
        </div>
        @if ($errors->any())
            <div class="col-12 text-danger small">{{ $errors->first() }}</div>
        @endif
    </form>

    <div class="card">
        <div class="card-header d-flex align-items-center py-3">
            <span class="fw-semibold">{{ $team->name }}</span>
            <span class="badge text-bg-secondary ms-2">{{ $notifications->total() }}</span>
        </div>

        @if ($notifications->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-envelope text-body-secondary" style="font-size: 2.5rem"></i>
                <p class="mt-3 mb-1 fw-semibold">{{ array_filter($filters) ? 'No notification matches these filters' : 'No notification sent yet' }}</p>
                @unless (array_filter($filters))
                    <p class="text-body-secondary small mb-0">Emails sent for this team's alerts appear here, according to the notification rules.</p>
                @endunless
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3 text-nowrap">Date</th>
                            <th>Alert</th>
                            <th>Event</th>
                            <th>Recipient</th>
                            <th>Channel</th>
                            <th class="pe-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($notifications as $notification)
                            @php($notified = $notification->alert)
                            <tr>
                                <td class="ps-3 small text-nowrap">
                                    <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->toDisplay()->format('d/m/Y H:i:s') }}</time>
                                </td>
                                <td>
                                    <x-severity-badge :severity="$notified->severity" />
                                    <a href="{{ route('targets.show', [$notified->target, 'tab' => 'alerts']) }}" class="fw-semibold text-body text-decoration-none ms-1">{{ $notified->target->name }}</a>
                                    <div class="small text-body-secondary">{{ $notified->message }}</div>
                                </td>
                                <td class="small">{{ $notification->event->label() }}</td>
                                <td class="small">
                                    @if ($notification->channel === \App\Enums\NotificationChannel::Webhook)
                                        {{-- Webhook (SCRUM-75) : son nom n'est pas une donnée personnelle, visible de tous --}}
                                        {{ $notification->recipient }}@unless ($notification->webhook_id)<span class="text-body-secondary"> (deleted)</span>@endunless
                                    @else
                                        {{ $notification->user?->name ?? 'Deleted user' }}
                                        @if ($showEmails && $notification->user)
                                            <div class="text-body-secondary">{{ $notification->recipient }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="small text-nowrap"><i class="bi {{ $notification->channel === \App\Enums\NotificationChannel::Webhook ? 'bi-broadcast' : 'bi-envelope' }} me-1"></i>{{ $notification->channel->label() }}</td>
                                <td class="pe-3 small text-nowrap">
                                    @switch($notification->status)
                                        @case(\App\Enums\NotificationStatus::Sent)
                                            <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>{{ $notification->status->label() }}</span>
                                            <div class="text-body-secondary">{{ $notification->sent_at?->toDisplay()->format('H:i:s') }}</div>
                                            @break
                                        @case(\App\Enums\NotificationStatus::Failed)
                                            <span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>{{ $notification->status->label() }}</span>
                                            <div class="text-body-secondary text-wrap" style="max-width: 22rem">
                                                after {{ $notification->attempts }} {{ Str::plural('attempt', $notification->attempts) }}@if ($showErrors && $notification->error): {{ $notification->error }}@endif
                                            </div>
                                            @break
                                        @default
                                            <span class="text-warning"><i class="bi bi-hourglass-split me-1"></i>{{ $notification->status->label() }}</span>
                                    @endswitch
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($notifications->hasPages())
                <div class="card-footer pt-3 small">{{ $notifications->onEachSide(1)->links() }}</div>
            @endif
        @endif
    </div>
@endsection
