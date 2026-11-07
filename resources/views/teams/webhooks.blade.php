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

    {{-- Secret de signature : affiché une seule fois, juste après la création ou la régénération --}}
    @if ($canManage && session('webhook_secret'))
        <div class="alert alert-warning" role="alert">
            <div class="fw-semibold mb-1"><i class="bi bi-key me-1"></i>Signing secret: copy it now, it will not be shown again</div>
            <code class="d-block user-select-all text-break fs-6">{{ session('webhook_secret.secret') }}</code>
            <div class="small mt-1">The receiver uses it to check the <code>X-Platform-Signature</code> header (see the README).</div>
        </div>
    @endif

    <div class="row g-4">
        <div class="@if ($canManage) col-xxl-8 @else col-12 @endif">
            <div class="card">
                <div class="card-header d-flex align-items-center py-3">
                    <span class="fw-semibold">{{ $team->name }}</span>
                    <span class="ms-2 small text-body-secondary">Webhooks</span>
                    @unless ($canManage)
                        <span class="badge text-bg-secondary ms-auto"><i class="bi bi-lock me-1"></i>Read only</span>
                    @endunless
                </div>
                @if ($webhooks->isEmpty())
                    <div class="card-body text-body-secondary small">
                        No webhook yet. A webhook posts the team's alerts to Slack, Microsoft Teams or any HTTPS endpoint.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-3">Name</th>
                                    <th>Type</th>
                                    <th>Sends</th>
                                    <th>Status</th>
                                    <th>Last delivery</th>
                                    @if ($canManage)<th class="text-end pe-3">Actions</th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($webhooks as $hook)
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-semibold">{{ $hook->name }}</div>
                                            @if ($canManage)
                                                <div class="small text-body-secondary font-monospace">{{ $hook->maskedUrl() }}</div>
                                            @endif
                                        </td>
                                        <td class="text-nowrap">{{ $hook->type->label() }}</td>
                                        <td class="small">
                                            <div>{{ $hook->severitiesLabel() }}</div>
                                            <div class="text-body-secondary">{{ $hook->eventsLabel() }}</div>
                                        </td>
                                        <td class="text-nowrap">
                                            @if ($hook->active)
                                                <span class="badge text-bg-success">Enabled</span>
                                            @else
                                                <span class="badge text-bg-secondary">Disabled</span>
                                            @endif
                                            @if ($hook->consecutive_failures > 0)
                                                <div class="small text-danger mt-1">{{ $hook->consecutive_failures }} failed in a row</div>
                                            @endif
                                        </td>
                                        <td class="small text-nowrap">
                                            @if ($last = $hook->lastNotification)
                                                <span class="badge @if ($last->status === \App\Enums\NotificationStatus::Sent) text-bg-success @elseif ($last->status === \App\Enums\NotificationStatus::Failed) text-bg-danger @else text-bg-secondary @endif">{{ $last->status->label() }}</span>
                                                <span class="text-body-secondary ms-1">{{ ($last->sent_at ?? $last->created_at)->toDisplay()->format('d/m/Y H:i') }}</span>
                                            @else
                                                <span class="text-body-secondary">Never</span>
                                            @endif
                                        </td>
                                        @if ($canManage)
                                            <td class="text-end pe-3">
                                                <div class="d-inline-flex gap-1">
                                                    <form method="POST" action="{{ route('teams.notifications.webhooks.test', [$team, $hook]) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap"><i class="bi bi-send me-1"></i>Send test</button>
                                                    </form>
                                                    <a href="{{ route('teams.notifications.webhooks.edit', [$team, $hook]) }}" class="btn btn-outline-secondary btn-sm" title="Edit" aria-label="Edit {{ $hook->name }}"><i class="bi bi-pencil"></i></a>
                                                    <div class="dropdown">
                                                        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for {{ $hook->name }}"><i class="bi bi-three-dots"></i></button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li>
                                                                <form method="POST" action="{{ route('teams.notifications.webhooks.toggle', [$team, $hook]) }}">
                                                                    @csrf
                                                                    @method('PATCH')
                                                                    <button type="submit" class="dropdown-item">
                                                                        @if ($hook->active)<i class="bi bi-pause-circle me-2"></i>Disable @else<i class="bi bi-play-circle me-2"></i>Enable @endif
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            @if ($hook->type === \App\Enums\WebhookType::Generic)
                                                                <li>
                                                                    <form method="POST" action="{{ route('teams.notifications.webhooks.secret', [$team, $hook]) }}"
                                                                          data-confirm="Regenerate the signing secret of {{ $hook->name }}? The current secret stops working immediately.">
                                                                        @csrf
                                                                        <button type="submit" class="dropdown-item"><i class="bi bi-key me-2"></i>Regenerate secret</button>
                                                                    </form>
                                                                </li>
                                                            @endif
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li>
                                                                <form method="POST" action="{{ route('teams.notifications.webhooks.destroy', [$team, $hook]) }}"
                                                                      data-confirm="Delete the webhook {{ $hook->name }}? Its delivery history is kept.">
                                                                    @csrf
                                                                    @method('DELETE')
                                                                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                                                </form>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                    {{-- Résultat du bouton « Send test » --}}
                                    @if ($canManage && session('webhook_test.id') === $hook->id)
                                        @php($result = session('webhook_test'))
                                        <tr>
                                            <td colspan="6" class="ps-3 py-2 border-top-0">
                                                <div class="alert @if ($result['ok']) alert-success @else alert-danger @endif mb-0 py-2 small" role="status">
                                                    @if ($result['ok'])
                                                        <i class="bi bi-check-circle me-1"></i>Test sent: {{ $result['message'] }}
                                                    @else
                                                        <i class="bi bi-x-circle me-1"></i>Test failed after {{ $result['ms'] }} ms: {{ $result['message'] }}
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        @if ($canManage)
            <div class="col-xxl-4">
                <form method="POST" action="{{ route('teams.notifications.webhooks.store', $team) }}" class="card">
                    @csrf
                    <div class="card-header py-3 fw-semibold"><i class="bi bi-plus-lg me-1"></i>New webhook</div>
                    <div class="card-body">
                        @if ($webhooks->count() >= $max)
                            <p class="small text-body-secondary mb-0">This team has reached the limit of {{ $max }} webhooks.</p>
                        @else
                            @include('teams._webhook-form')
                        @endif
                    </div>
                    @if ($webhooks->count() < $max)
                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Create</button>
                        </div>
                    @endif
                </form>
            </div>
        @endif
    </div>
@endsection
