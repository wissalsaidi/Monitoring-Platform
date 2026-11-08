<?php

use App\Http\Middleware\EnforceSsoSession;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CSP, X-Frame-Options, HSTS… sur toutes les pages (SCRUM-61)
        // + compte désactivé déconnecté (SCRUM-84), durée des sessions SSO et SSO obligatoire (SCRUM-90)
        $middleware->web(append: [SecurityHeaders::class, EnsureAccountIsActive::class, EnforceSsoSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Jamais de secret dans la session après une erreur de validation (mot de passe d'une base, SCRUM-71)
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'credentials']);
    })->create();
