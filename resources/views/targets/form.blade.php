@extends('layouts.app')

@use('App\Enums\TargetType')

@section('title', $target ? 'Edit '.$target->name : 'Add Monitoring Target')

@section('content')
    @php
        // Types du cahier des charges §5 : disponibles s'ils ont un provisioner (config monitoring.provisioners, SCRUM-66)
        $types = collect([TargetType::Api, TargetType::Website, TargetType::Server, TargetType::Container, TargetType::Database, TargetType::Network])
            ->map(fn (TargetType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'icon' => $type->icon(),
                'enabled' => in_array($type, $supportedTypes, true),
            ]);

        // Champs propres au type (SCRUM-69) : seule la section du type choisi est affichée et envoyée
        // (les autres sont masquées et désactivées ; resources/js/target-form.js les bascule au changement de type)
        $selectedType = old('type', $target?->type->value ?? 'api');
        $httpType = in_array($selectedType, ['api', 'website'], true);
        $serverType = $selectedType === 'server';
        $serverDefaults = \App\Monitoring\Provisioners\ServerProvisioner::class;
    @endphp

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST" action="{{ $target ? route('targets.update', $target) : route('targets.store') }}" class="card" novalidate>
                @csrf
                @if ($target)
                    @method('PUT')
                @endif

                <div class="card-body p-4">
                    {{-- Type --}}
                    <p class="section-label mb-2">Type</p>
                    <div class="row g-2 mb-4">
                        @foreach ($types as $type)
                            <div class="col-4 col-md-2 type-option">
                                <input type="radio" class="btn-check" name="type" id="type-{{ $type['value'] }}" value="{{ $type['value'] }}"
                                       @checked(old('type', $target?->type->value ?? 'api') === $type['value']) @disabled(! $type['enabled'] || $target)>
                                <label class="btn" for="type-{{ $type['value'] }}" @unless ($type['enabled']) title="Coming soon" @endunless>
                                    <i class="bi {{ $type['icon'] }}"></i>
                                    <span class="small">{{ $type['label'] }}</span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                    @error('type')<div class="text-danger small mb-3">{{ $message }}</div>@enderror

                    {{-- Identité --}}
                    <p class="section-label mb-2">Target</p>
                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $target?->name) }}" maxlength="100"
                               class="form-control @error('name') is-invalid @enderror" placeholder="Payment API" required autofocus>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3 {{ $httpType ? '' : 'd-none' }}" data-type-fields="api website">
                        <label for="url" class="form-label">URL</label>
                        <input id="url" name="url" type="url" value="{{ old('url', $target?->url) }}" @disabled(! $httpType)
                               class="form-control font-monospace-sm @error('url') is-invalid @enderror" placeholder="https://api.example.com/health" required>
                        @error('url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @else
                            <div class="form-text">
                                Zabbix calls this URL and expects a 2xx response. Demo API: <code>http://fake-api:8080/health</code>.
                                For a website with an <code>https://</code> URL, the SSL certificate expiry is checked too.
                            </div>
                        @enderror
                    </div>

                    {{-- Serveur (SCRUM-69) : agent Zabbix installé sur le serveur --}}
                    <div class="row g-3 mb-3 {{ $serverType ? '' : 'd-none' }}" data-type-fields="server">
                        <div class="col-md-8">
                            <label for="settings-address" class="form-label">IP address or host name</label>
                            <input id="settings-address" name="settings[address]" type="text" maxlength="255" @disabled(! $serverType)
                                   value="{{ old('settings.address', $target?->setting('address')) }}" placeholder="10.0.0.12"
                                   class="form-control font-monospace-sm @error('settings.address') is-invalid @enderror" required>
                            @error('settings.address')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">The Zabbix agent must run on the server and accept the Zabbix server. Demo: <code>zabbix-agent2</code></div>
                            @enderror
                        </div>
                        <div class="col-md-4">
                            <label for="settings-port" class="form-label">Agent port</label>
                            <input id="settings-port" name="settings[port]" type="number" min="1" max="65535" @disabled(! $serverType)
                                   value="{{ old('settings.port', $target?->setting('port') ?? $serverDefaults::DEFAULT_PORT) }}"
                                   class="form-control @error('settings.port') is-invalid @enderror" required>
                            @error('settings.port')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="description" class="form-label">Description <span class="text-body-secondary">(optional)</span></label>
                        <textarea id="description" name="description" rows="2" maxlength="500"
                                  class="form-control @error('description') is-invalid @enderror">{{ old('description', $target?->description) }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Supervision --}}
                    <p class="section-label mb-2">Monitoring</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="check_interval" class="form-label">Check interval</label>
                            <select id="check_interval" name="check_interval" class="form-select @error('check_interval') is-invalid @enderror">
                                @foreach ($intervals as $seconds => $label)
                                    <option value="{{ $seconds }}" @selected((int) old('check_interval', $target?->check_interval ?? 30) === $seconds)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('check_interval')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4 {{ $httpType ? '' : 'd-none' }}" data-type-fields="api website">
                            <label for="warning_threshold_ms" class="form-label"><i class="bi bi-circle-fill text-warning small me-1"></i>Warning threshold</label>
                            <div class="input-group has-validation">
                                <input id="warning_threshold_ms" name="warning_threshold_ms" type="number" min="1" max="{{ \App\Models\MonitoringTarget::MAX_THRESHOLD_MS }}" @disabled(! $httpType)
                                       value="{{ old('warning_threshold_ms', $target?->warning_threshold_ms ?? 500) }}" class="form-control @error('warning_threshold_ms') is-invalid @enderror" required>
                                <span class="input-group-text">ms</span>
                                @error('warning_threshold_ms')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="col-md-4 {{ $httpType ? '' : 'd-none' }}" data-type-fields="api website">
                            <label for="critical_threshold_ms" class="form-label"><i class="bi bi-circle-fill text-danger small me-1"></i>Critical threshold</label>
                            <div class="input-group has-validation">
                                <input id="critical_threshold_ms" name="critical_threshold_ms" type="number" min="1" max="{{ \App\Models\MonitoringTarget::MAX_THRESHOLD_MS }}" @disabled(! $httpType)
                                       value="{{ old('critical_threshold_ms', $target?->critical_threshold_ms ?? 1000) }}" class="form-control @error('critical_threshold_ms') is-invalid @enderror" required>
                                <span class="input-group-text">ms</span>
                                @error('critical_threshold_ms')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    {{-- Seuils d'un serveur, en % (SCRUM-69) : moyenne sur 5 minutes --}}
                    <div class="mt-3 {{ $serverType ? '' : 'd-none' }}" data-type-fields="server">
                        <div class="row g-2 small text-body-secondary mb-1">
                            <div class="col-4"></div>
                            <div class="col-4"><i class="bi bi-circle-fill text-warning small me-1"></i>Warning above</div>
                            <div class="col-4"><i class="bi bi-circle-fill text-danger small me-1"></i>Critical above</div>
                        </div>
                        @foreach ($serverDefaults::RESOURCES as $resource => ['label' => $label])
                            <div class="row g-2 align-items-start mb-2">
                                <div class="col-4 pt-2">{{ $label }}</div>
                                @foreach (['warning' => $serverDefaults::DEFAULT_WARNING, 'critical' => $serverDefaults::DEFAULT_CRITICAL] as $severity => $default)
                                    @php
                                        $field = "{$resource}_{$severity}";
                                    @endphp
                                    <div class="col-4">
                                        <div class="input-group input-group-sm has-validation">
                                            <input id="settings-{{ $field }}" name="settings[{{ $field }}]" type="number" min="1" max="100" @disabled(! $serverType)
                                                   value="{{ old("settings.$field", $target?->setting($field) ?? $default) }}" aria-label="{{ $label }} {{ $severity }} threshold"
                                                   class="form-control @error("settings.$field") is-invalid @enderror" required>
                                            <span class="input-group-text">%</span>
                                            @error("settings.$field")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                        <div class="form-text">Compared to the 5-minute average, so that a short spike does not raise an alert.</div>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-end gap-2 py-3">
                    <a href="{{ $target ? route('targets.show', $target) : route('dashboard') }}" class="btn btn-outline-secondary">Cancel</a>
                    @if ($target)
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save changes</button>
                    @else
                        <button type="submit" class="btn btn-primary"><i class="bi bi-play-circle me-1"></i>Create Monitoring</button>
                    @endif
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <p class="section-label mb-3">What happens next</p>
                    @if ($target)
                        <ol class="small text-body-secondary ps-3 mb-0 d-grid gap-2">
                            <li>The new name, URL, interval and thresholds are sent to Zabbix immediately.</li>
                            <li>Zabbix applies them at the next check.</li>
                            <li>Alerts already raised keep the threshold they were raised with.</li>
                            <li>The type cannot be changed: delete the target and create a new one.</li>
                        </ol>
                    @else
                    <ol class="small text-body-secondary ps-3 mb-0 d-grid gap-2">
                        <li>The target is saved in team <strong class="text-body">{{ $team->name }}</strong>.</li>
                        <li>Zabbix creates a check on the URL at the chosen interval.</li>
                        <li>Above <span class="text-warning">warning</span> → <x-status-pill :status="\App\Enums\TargetStatus::Warning" />, above <span class="text-danger">critical</span> or unreachable → <x-status-pill :status="\App\Enums\TargetStatus::Critical" />.</li>
                        <li>First results within a minute.</li>
                    </ol>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
