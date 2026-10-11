<?php

use Illuminate\Support\Facades\Route;

// Les routes de connexion / inscription / déconnexion sont fournies par Fortify.

Route::middleware('auth')->group(function () {
    Route::view('/', 'dashboard')->name('dashboard');
});
