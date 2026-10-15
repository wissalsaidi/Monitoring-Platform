@extends('layouts.app')

@section('title', 'Add Monitoring Target')

@section('content')
    @php
        // Types du cahier des charges §5 : seul API est disponible dans le MVP
        $types = [
            ['value' => 'api', 'label' => 'API', 'icon' => 'bi-braces', 'enabled' => true],
            ['value' => 'website', 'label' => 'Website', 'icon' => 'bi-globe2', 'enabled' => false],
            ['value' => 'server', 'label' => 'Server', 'icon' => 'bi-hdd-rack', 'enabled' => false],
            ['value' => 'container', 'label' => 'Container', 'icon' => 'bi-box-seam', 'enabled' => false],
            ['value' => 'database', 'label' => 'Database', 'icon' => 'bi-database', 'enabled' => false],
            ['value' => 'network', 'label' => 'Network', 'icon' => 'bi-diagram-3', 'enabled' => false],
        ];
    @endphp

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('targets.store') }}" class="card" novalidate>
                @csrf

                <div class="card-body p-4">
                    {{-- Type --}}
                    <p class="section-label mb-2">Type</p>
                    <div class="row g-2 mb-4">
                        @foreach ($types as $type)
                            <div class="col-4 col-md-2 type-option">
                                <input type="radio" class="btn-check" name="type" id="type-{{ $type['value'] }}" value="{{ $type['value'] }}"
                                       @checked(old('type', 'api') === $type['value']) @disabled(! $type['enabled'])>
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
                        <input id="name" name="name" type="text" value="{{ old('name') }}" maxlength="100"
                               class="form-control @error('name') is-invalid @enderror" placeholder="Payment API" required autofocus>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="url" class="form-label">URL</label>
                        <input id="url" name="url" type="url" value="{{ old('url') }}"
                               class="form-control font-monospace-sm @error('url') is-invalid @enderror" placeholder="https://api.example.com/health" required>
                        @error('url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @else
                            <div class="form-text">Zabbix calls this URL and expects a 2xx response. Demo API: <code>http://fake-api:8080/health</code></div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="description" class="form-label">Description <span class="text-body-secondary">(optional)</span></label>
                        <textarea id="description" name="description" rows="2" maxlength="500"
                                  class="form-control @error('description') is-invalid @enderror">{{ old('description') }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Supervision --}}
                    <p class="section-label mb-2">Monitoring</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="check_interval" class="form-label">Check interval</label>
                            <select id="check_interval" name="check_interval" class="form-select @error('check_interval') is-invalid @enderror">
                                @foreach ($intervals as $seconds => $label)
                                    <option value="{{ $seconds }}" @selected((int) old('check_interval', 30) === $seconds)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('check_interval')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4">
                            <label for="warning_threshold_ms" class="form-label"><i class="bi bi-circle-fill text-warning small me-1"></i>Warning threshold</label>
                            <div class="input-group has-validation">
                                <input id="warning_threshold_ms" name="warning_threshold_ms" type="number" min="1" max="{{ \App\Models\MonitoringTarget::MAX_THRESHOLD_MS }}"
                                       value="{{ old('warning_threshold_ms', 500) }}" class="form-control @error('warning_threshold_ms') is-invalid @enderror" required>
                                <span class="input-group-text">ms</span>
                                @error('warning_threshold_ms')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="critical_threshold_ms" class="form-label"><i class="bi bi-circle-fill text-danger small me-1"></i>Critical threshold</label>
                            <div class="input-group has-validation">
                                <input id="critical_threshold_ms" name="critical_threshold_ms" type="number" min="1" max="{{ \App\Models\MonitoringTarget::MAX_THRESHOLD_MS }}"
                                       value="{{ old('critical_threshold_ms', 1000) }}" class="form-control @error('critical_threshold_ms') is-invalid @enderror" required>
                                <span class="input-group-text">ms</span>
                                @error('critical_threshold_ms')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-end gap-2 py-3">
                    <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-play-circle me-1"></i>Create Monitoring</button>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <p class="section-label mb-3">What happens next</p>
                    <ol class="small text-body-secondary ps-3 mb-0 d-grid gap-2">
                        <li>The target is saved in team <strong class="text-body">{{ $team->name }}</strong>.</li>
                        <li>Zabbix creates a check on the URL at the chosen interval.</li>
                        <li>Above <span class="text-warning">warning</span> → <x-status-pill :status="\App\Enums\TargetStatus::Warning" />, above <span class="text-danger">critical</span> or unreachable → <x-status-pill :status="\App\Enums\TargetStatus::Critical" />.</li>
                        <li>First results within a minute.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
@endsection
