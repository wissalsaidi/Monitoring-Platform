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
                    <li><a class="dropdown-item @if ($other->is($team)) active @endif" href="{{ route(request()->route()->getName(), $other) }}">{{ $other->name }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection

@section('content')
    @include('teams._notification-tabs')

    <div class="row g-4">
        <div class="col-xl-8">
            {{-- Règles de notification (SCRUM-55) : un choix par sévérité et par événement --}}
            <form method="POST" action="{{ route('teams.notifications.update', $team) }}" class="card">
                @csrf
                @method('PUT')
                <div class="card-header d-flex align-items-center py-3">
                    <span class="fw-semibold">{{ $team->name }}</span>
                    <span class="ms-2 small text-body-secondary">Notification rules</span>
                    @unless ($canManage)
                        <span class="badge text-bg-secondary ms-auto"><i class="bi bi-lock me-1"></i>Read only</span>
                    @endunless
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">Severity</th>
                                @foreach (\App\Enums\NotificationEvent::cases() as $event)
                                    <th>When an alert is {{ strtolower($event->label()) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($severities as $severity)
                                <tr>
                                    <td class="ps-3"><x-severity-badge :severity="$severity" /></td>
                                    @foreach (\App\Enums\NotificationEvent::cases() as $event)
                                        @php($name = "rules[{$severity->value}][{$event->value}]")
                                        <td>
                                            <select name="{{ $name }}" class="form-select form-select-sm"
                                                    aria-label="{{ $severity->label() }}, {{ strtolower($event->label()) }}" @disabled(! $canManage)>
                                                @foreach (\App\Enums\NotificationChannel::cases() as $channel)
                                                    <option value="{{ $channel->value }}" @selected(old("rules.{$severity->value}.{$event->value}", $channels[$severity->value][$event->value]->value) === $channel->value)>{{ $channel->label() }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($canManage)
                    <div class="card-footer d-flex align-items-center gap-3">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Save</button>
                        @if ($errors->any())
                            <span class="text-danger small">{{ $errors->first() }}</span>
                        @endif
                    </div>
                @endif
            </form>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-body small">
                    <h2 class="h6"><i class="bi bi-info-circle me-1"></i>How it works</h2>
                    <ul class="mb-0 ps-3">
                        <li><strong>Email</strong>: every verified member of the team receives an email.</li>
                        <li><strong>Dashboard only</strong>: the alert is shown in the application, no email is sent.</li>
                        <li>A resolution email is only sent to members who were emailed when the alert opened.</li>
                        <li>Admins and Managers can change these rules; every change is recorded in the audit log.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
