<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitoringTargetController;
use Illuminate\Support\Facades\Route;

// Les routes de connexion / inscription / déconnexion sont fournies par Fortify.

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/targets/create', [MonitoringTargetController::class, 'create'])->name('targets.create');
    Route::post('/targets', [MonitoringTargetController::class, 'store'])->name('targets.store');
    Route::get('/targets/{target}', [MonitoringTargetController::class, 'show'])->name('targets.show');
});
