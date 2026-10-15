<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
<main class="min-vh-100 d-flex align-items-center justify-content-center p-3">
    <div class="w-100" style="max-width: 420px">
        <div class="text-center mb-4">
            <i class="bi bi-activity text-primary" style="font-size: 2.5rem"></i>
            <h1 class="h4 mt-2 mb-0">{{ config('app.name') }}</h1>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                @yield('content')
            </div>
        </div>
    </div>
</main>
</body>
</html>
