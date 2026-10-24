<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
@php
    $nav = [
        ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'bi-grid-1x2', 'label' => 'Dashboard'],
        // Menu Monitoring par type (cahier des charges §44, SCRUM-72)
        ['route' => 'targets.index', 'match' => 'targets.*', 'icon' => 'bi-hdd-network', 'label' => 'Monitoring',
            'menu' => app(\App\Monitoring\ProvisionerRegistry::class)->supportedTypes()],
        ['route' => 'alerts.index', 'match' => 'alerts.*', 'icon' => 'bi-bell', 'label' => 'Alerts'],
        // Membres de la team (SCRUM-59) : seulement pour qui a user.view dans au moins une team
        ...(auth()->user()->teams->contains(fn ($team) => auth()->user()->hasPermission(\App\Enums\Permission::UserView, $team))
            ? [['route' => 'teams.current', 'match' => 'teams.members.*', 'icon' => 'bi-people', 'label' => 'Team members']] : []),
        // Journal d'audit (SCRUM-60) : seulement pour qui a audit.view dans au moins une team
        ...(auth()->user()->teams->contains(fn ($team) => auth()->user()->hasPermission(\App\Enums\Permission::AuditView, $team))
            ? [['route' => 'teams.audit.current', 'match' => 'teams.audit.*', 'icon' => 'bi-journal-text', 'label' => 'Audit log']] : []),
        ['route' => null, 'match' => null, 'icon' => 'bi-graph-up', 'label' => 'Dashboards (soon)'],
    ];
@endphp

<nav class="app-rail" aria-label="Main navigation">
    <a href="{{ route('dashboard') }}" class="app-rail-brand" data-bs-toggle="tooltip" data-bs-placement="right" title="{{ config('app.name') }}">
        <i class="bi bi-activity"></i>
    </a>

    @foreach ($nav as $item)
        @if (isset($item['menu']))
            <div class="dropend">
                <button type="button" class="app-rail-link border-0 bg-transparent {{ request()->routeIs($item['match']) ? 'active' : '' }}"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ $item['label'] }}" title="{{ $item['label'] }}">
                    <i class="bi {{ $item['icon'] }}"></i>
                </button>
                <ul class="dropdown-menu shadow">
                    <li><h6 class="dropdown-header">{{ $item['label'] }}</h6></li>
                    <li><a class="dropdown-item" href="{{ route($item['route']) }}"><i class="bi bi-list-ul me-2"></i>All targets</a></li>
                    <li><hr class="dropdown-divider"></li>
                    @foreach ($item['menu'] as $type)
                        <li>
                            <a class="dropdown-item {{ request()->routeIs('targets.index') && request('type') === $type->value ? 'active' : '' }}"
                               href="{{ route($item['route'], ['type' => $type->value]) }}">
                                <i class="bi {{ $type->icon() }} me-2"></i>{{ $type->pluralLabel() }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
            @continue
        @endif
        <a href="{{ $item['route'] ? route($item['route']) : '#' }}"
           class="app-rail-link {{ $item['match'] && request()->routeIs($item['match']) ? 'active' : '' }} {{ $item['route'] ? '' : 'disabled' }}"
           data-bs-toggle="tooltip" data-bs-placement="right" title="{{ $item['label'] }}" aria-label="{{ $item['label'] }}">
            <i class="bi {{ $item['icon'] }}"></i>
        </a>
    @endforeach

    <div class="mt-auto dropup">
        <button class="app-rail-link border-0 bg-transparent" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account">
            <i class="bi bi-person-circle"></i>
        </button>
        <ul class="dropdown-menu shadow">
            <li><h6 class="dropdown-header text-body">{{ auth()->user()->name }}<br><span class="fw-normal text-body-secondary">{{ auth()->user()->email }}</span></h6></li>
            @if ($team = auth()->user()->defaultTeam())
                <li><span class="dropdown-item-text small text-body-secondary"><i class="bi bi-people me-1"></i>Team: {{ $team->name }}</span></li>
            @endif
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="{{ route('account.edit') }}"><i class="bi bi-person-gear me-2"></i>My account</a></li>
            <li>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Log out</button>
                </form>
            </li>
        </ul>
    </div>
</nav>

<div class="app-main">
    <header class="app-topbar gap-3">
        <h1 class="page-title">@yield('title', 'Dashboard')</h1>
        <div class="ms-auto d-flex align-items-center gap-2">
            @yield('actions')
            <span class="text-body-secondary small d-none d-md-inline"><i class="bi bi-person me-1"></i>{{ auth()->user()->name }}</span>
        </div>
    </header>

    <main class="app-content">
        @if (\App\Support\ZabbixSyncHealth::isFailing())
            {{-- Zabbix injoignable lors de la dernière synchro (§47) : message générique, détail dans les logs --}}
            <div class="alert alert-warning d-flex align-items-center gap-2" role="status">
                <i class="bi bi-exclamation-triangle"></i>
                <span>Monitoring service temporarily unavailable. Statuses and alerts may be out of date.</span>
            </div>
        @endif

        @foreach (['success' => 'check-circle', 'warning' => 'exclamation-triangle', 'error' => 'exclamation-octagon'] as $type => $icon)
            @if (session($type))
                <div class="alert alert-{{ $type === 'error' ? 'danger' : $type }} d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-{{ $icon }}"></i> {{ session($type) }}
                </div>
            @endif
        @endforeach

        @yield('content')
    </main>
</div>
</body>
</html>
