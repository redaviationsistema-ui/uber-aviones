<?php

use App\Http\Controladores\AeronaveControlador;
use App\Http\Controladores\AeropuertoBusquedaControlador;
use App\Http\Controladores\AutenticacionControlador;
use App\Http\Controladores\BiometricControlador;
use App\Http\Controladores\DocuSignWebhookControlador;
use App\Http\Controladores\PlanControlador;
use App\Modelos\Aeropuerto;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->group(function () {
    Route::get('/plans', [PlanControlador::class, 'index']);
    Route::get('/airports/search', AeropuertoBusquedaControlador::class);
    Route::get('/airports', fn () => response()->json([
        'success' => true,
        'airports' => Aeropuerto::where('status', 'active')->orderBy('icao')->get(),
    ]));
    Route::get('/aircraft-preview', [AeronaveControlador::class, 'preview']);
    Route::post('/biometric/detect-face', [BiometricControlador::class, 'detectFace']);
    Route::get('/biometric/selfies/{user}', [BiometricControlador::class, 'showStoredSelfie'])
        ->middleware('signed')
        ->name('public.biometric-selfies.show');
    Route::post('/docusign/webhook', [DocuSignWebhookControlador::class, 'handle']);
});

Route::get('/airports/search', AeropuertoBusquedaControlador::class);
Route::post('/provider/register', [AutenticacionControlador::class, 'register']);
