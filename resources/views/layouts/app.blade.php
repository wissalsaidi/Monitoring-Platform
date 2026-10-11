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

    <main class="flex-grow-1 p-4">
        @yield('content')
    </main>
</div>
</body>
</html>
