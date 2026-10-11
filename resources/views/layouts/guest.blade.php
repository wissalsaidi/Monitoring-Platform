<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="guest-bg">
<main class="min-vh-100 d-flex align-items-center justify-content-center p-3">
    <div class="w-100" style="max-width: 420px">
        <div class="text-center mb-4">
            <span class="d-inline-grid rounded-3 p-3 text-primary" style="background: rgba(91, 141, 239, .15)">
                <i class="bi bi-activity fs-2"></i>
            </span>
            <h1 class="h4 mt-3 mb-1">{{ config('app.name') }}</h1>
            <p class="text-body-secondary small mb-0">Monitoring &amp; Alerting</p>
        </div>

        <div class="card shadow-lg">
            <div class="card-body p-4">
                @yield('content')
            </div>
        </div>
    </div>
</main>
</body>
</html>
