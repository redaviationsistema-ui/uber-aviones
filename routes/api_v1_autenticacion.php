<?php

use App\Http\Controladores\AutenticacionControlador;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AutenticacionControlador::class, 'register']);
    Route::post('/login', [AutenticacionControlador::class, 'login']);
    Route::post('/forgot-password', [AutenticacionControlador::class, 'forgotPassword']);
    Route::post('/reset-password', [AutenticacionControlador::class, 'resetPassword']);

    Route::middleware('auth.token')->group(function () {
        Route::post('/logout', [AutenticacionControlador::class, 'logout']);
        Route::get('/me', [AutenticacionControlador::class, 'me']);
        Route::get('/redirect-dashboard', [AutenticacionControlador::class, 'redirectDashboard']);
        Route::post('/verify-email', [AutenticacionControlador::class, 'verifyEmail']);
    });
});
