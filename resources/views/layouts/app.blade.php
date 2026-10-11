<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
<div class="d-flex">
    <aside class="sidebar bg-body border-end p-3 d-none d-md-block">
        <a href="{{ route('dashboard') }}" class="d-flex align-items-center gap-2 mb-4 text-decoration-none text-body">
            <i class="bi bi-activity fs-4 text-primary"></i>
            <span class="fw-semibold">{{ config('app.name') }}</span>
        </a>
        <nav class="nav flex-column gap-1">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                <i class="bi bi-speedometer2 me-2"></i>Dashboard
            </a>
            <a class="nav-link disabled" href="#"><i class="bi bi-hdd-network me-2"></i>Monitoring</a>
            <a class="nav-link disabled" href="#"><i class="bi bi-bell me-2"></i>Alerts</a>
            <a class="nav-link disabled" href="#"><i class="bi bi-graph-up me-2"></i>Dashboards</a>
            <a class="nav-link disabled" href="#"><i class="bi bi-gear me-2"></i>Settings</a>
        </nav>
    </aside>

    <div class="flex-grow-1">
        <header class="bg-body border-bottom px-4 py-2 d-flex justify-content-end">
            <div class="dropdown">
                <button class="btn btn-link text-body text-decoration-none dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle me-1"></i>{{ auth()->user()->name }}
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><h6 class="dropdown-header">{{ auth()->user()->email }}</h6></li>
                    @if ($team = auth()->user()->defaultTeam())
                        <li><span class="dropdown-item-text small text-body-secondary"><i class="bi bi-people me-1"></i>Team: {{ $team->name }}</span></li>
                    @endif
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Log out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </header>

        <main class="p-4">
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
