<?php

use App\Http\Controladores\AeronaveControlador;
use App\Http\Controladores\AeropuertoBusquedaControlador;
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
});
