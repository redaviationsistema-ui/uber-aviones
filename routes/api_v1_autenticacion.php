<?php

use App\Http\Controladores\AutenticacionControlador;
use App\Http\Controladores\DispositivoUsuarioControlador;
use App\Http\Controladores\OcrDocumentoControlador;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AutenticacionControlador::class, 'register']);
    Route::post('/registration/identification', [AutenticacionControlador::class, 'storeRegistrationIdentification']);
    Route::post('/login', [AutenticacionControlador::class, 'login'])->middleware('throttle:auth.login');
    Route::post('/ocr/scan-document', [OcrDocumentoControlador::class, 'scanDocument']);
    Route::post('/forgot-password', [AutenticacionControlador::class, 'forgotPassword'])->middleware('throttle:auth.password-reset');
    Route::post('/reset-password', [AutenticacionControlador::class, 'resetPassword'])->middleware('throttle:auth.password-reset-confirm');
    Route::get('/password/reset/{token}', [AutenticacionControlador::class, 'showResetPassword'])
        ->middleware('throttle:auth.password-reset-confirm');
    Route::get('/email/verify/{id}/{hash}', [AutenticacionControlador::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:auth.email-verification'])
        ->name('verification.verify');

    Route::middleware('auth.token')->group(function () {
        Route::post('/devices', [DispositivoUsuarioControlador::class, 'store']);
        Route::put('/devices/{deviceUuid}', [DispositivoUsuarioControlador::class, 'update']);
        Route::delete('/devices/{deviceUuid}', [DispositivoUsuarioControlador::class, 'destroy']);
        Route::post('/logout', [AutenticacionControlador::class, 'logout']);
        Route::get('/me', [AutenticacionControlador::class, 'me']);
        Route::get('/redirect-dashboard', [AutenticacionControlador::class, 'redirectDashboard']);
        Route::post('/verify-email', [AutenticacionControlador::class, 'sendEmailVerificationNotification'])
            ->middleware('throttle:auth.email-verification-notification');
    });
});
