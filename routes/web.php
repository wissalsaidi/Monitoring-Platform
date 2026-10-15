<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitoringTargetController;
use App\Http\Controllers\TargetMetricsController;
use Illuminate\Support\Facades\Route;

// Les routes de connexion / inscription / déconnexion sont fournies par Fortify.

// Email vérifié obligatoire (SCRUM-32) : sinon redirection vers la page « Verify your email »
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/targets', [MonitoringTargetController::class, 'index'])->name('targets.index');
    Route::get('/targets/create', [MonitoringTargetController::class, 'create'])->name('targets.create');
    Route::post('/targets', [MonitoringTargetController::class, 'store'])->name('targets.store');
    Route::get('/alerts', [AlertController::class, 'active'])->name('alerts.index');
    Route::get('/alerts/history', [AlertController::class, 'history'])->name('alerts.history');
    Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');

    Route::get('/targets/{target}', [MonitoringTargetController::class, 'show'])->name('targets.show');
    Route::get('/targets/{target}/edit', [MonitoringTargetController::class, 'edit'])->name('targets.edit');
    Route::put('/targets/{target}', [MonitoringTargetController::class, 'update'])->name('targets.update');
    Route::delete('/targets/{target}', [MonitoringTargetController::class, 'destroy'])->name('targets.destroy');
    // API interne des graphiques (JSON)
    Route::get('/targets/{target}/metrics/response-time', [TargetMetricsController::class, 'responseTime'])
        ->name('targets.metrics.response-time');
});
