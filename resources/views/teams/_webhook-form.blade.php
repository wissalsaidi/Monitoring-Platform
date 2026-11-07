{{-- Champs d'un webhook (SCRUM-78), création et modification. L'URL n'est jamais pré-remplie : vide = inchangée --}}
@php($editing = isset($webhook))
<div class="mb-3">
    <label for="webhook-name" class="form-label">Name</label>
    <input type="text" id="webhook-name" name="name" maxlength="100" required class="form-control form-control-sm @error('name') is-invalid @enderror"
           value="{{ old('name', $webhook->name ?? '') }}" placeholder="#ops-alerts">
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="mb-3">
    <label for="webhook-type" class="form-label">Type</label>
    <select id="webhook-type" name="type" class="form-select form-select-sm @error('type') is-invalid @enderror">
        @foreach (\App\Enums\WebhookType::cases() as $type)
            <option value="{{ $type->value }}" @selected(old('type', $webhook->type->value ?? 'slack') === $type->value)>{{ $type->label() }}</option>
        @endforeach
    </select>
    @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="mb-3">
    <label for="webhook-url" class="form-label">URL</label>
    <input type="url" id="webhook-url" name="url" maxlength="2000" autocomplete="off" spellcheck="false" @required(! $editing)
           class="form-control form-control-sm @error('url') is-invalid @enderror"
           placeholder="{{ $editing ? $webhook->maskedUrl() : 'https://hooks.slack.com/services/…' }}">
    @error('url')<div class="invalid-feedback">{{ $message }}</div>@enderror
    <div class="form-text">
        @if ($editing)
            Leave empty to keep the current URL. It is never shown again in full.
        @else
            HTTPS only. Stored encrypted and never shown again in full.
        @endif
    </div>
</div>
<fieldset class="mb-3">
    <legend class="form-label fs-6">Severities</legend>
    @php($severities = old('severities', isset($webhook) ? $webhook->severities->map->value->all() : ['critical', 'high']))
    @foreach (collect(\App\Enums\AlertSeverity::cases())->sortByDesc(fn ($s) => $s->rank()) as $severity)
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="severities[]" value="{{ $severity->value }}" id="sev-{{ $severity->value }}"
                   @checked(in_array($severity->value, $severities, true))>
            <label class="form-check-label" for="sev-{{ $severity->value }}">{{ $severity->label() }}</label>
        </div>
    @endforeach
    @error('severities')<div class="text-danger small">{{ $message }}</div>@enderror
</fieldset>
<fieldset class="mb-3">
    <legend class="form-label fs-6">When an alert is</legend>
    @php($events = old('events', isset($webhook) ? $webhook->events->map->value->all() : ['opened', 'resolved']))
    @foreach (\App\Enums\NotificationEvent::cases() as $event)
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="events[]" value="{{ $event->value }}" id="event-{{ $event->value }}"
                   @checked(in_array($event->value, $events, true))>
            <label class="form-check-label" for="event-{{ $event->value }}">{{ strtolower($event->label()) }}</label>
        </div>
    @endforeach
    @error('events')<div class="text-danger small">{{ $message }}</div>@enderror
</fieldset>
@if ($editing)
    <div class="form-check form-switch mb-3">
        <input type="hidden" name="active" value="0">
        <input class="form-check-input" type="checkbox" role="switch" name="active" value="1" id="webhook-active" @checked(old('active', $webhook->active))>
        <label class="form-check-label" for="webhook-active">Enabled</label>
    </div>
@endif
