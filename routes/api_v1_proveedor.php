<?php

use App\Http\Controladores\AeronaveControlador;
use App\Http\Controladores\AutenticacionControlador;
use App\Http\Controladores\NotificacionControlador;
use App\Http\Controladores\ProveedorControlador;
use App\Http\Controladores\CotizacionControlador;
use App\Http\Controladores\ReservaControlador;
use Illuminate\Support\Facades\Route;

Route::prefix('proveedor')->middleware(['auth.token', 'role:provider,admin'])->group(function () {
    Route::get('/dashboard', [ProveedorControlador::class, 'dashboard']);
    Route::get('/perfil', [AutenticacionControlador::class, 'me']);
    Route::put('/perfil', [AutenticacionControlador::class, 'updatePerfil']);

    Route::apiResource('aeronaves', AeronaveControlador::class)
        ->parameters(['aeronaves' => 'aircraft']);
    Route::post('/aeronaves/{aircraft}/imagenes', [AeronaveControlador::class, 'storeImage']);
    Route::delete('/aeronaves/{aircraft}/imagenes/{image}', [AeronaveControlador::class, 'destroyImage']);
    Route::post('/aeronaves/{aircraft}/documentos', [AeronaveControlador::class, 'storeDocument']);
    Route::delete('/aeronaves/{aircraft}/documentos/{document}', [AeronaveControlador::class, 'destroyDocument']);

    Route::get('/disponibilidad', [AeronaveControlador::class, 'availability']);
    Route::post('/disponibilidad', [AeronaveControlador::class, 'storeAvailability']);
    Route::put('/disponibilidad/{availability}', [AeronaveControlador::class, 'updateAvailability']);
    Route::delete('/disponibilidad/{availability}', [AeronaveControlador::class, 'destroyAvailability']);

    Route::get('/solicitudes', [ProveedorControlador::class, 'requests']);
    Route::get('/solicitudes/{flightRequest}', [ProveedorControlador::class, 'showRequest']);
    Route::post('/solicitudes/{flightRequest}/aceptar', [ProveedorControlador::class, 'acceptRequest']);
    Route::post('/solicitudes/{flightRequest}/rechazar', [ProveedorControlador::class, 'rejectRequest']);

    Route::get('/cotizaciones', [CotizacionControlador::class, 'providerIndex']);
    Route::post('/cotizaciones', [CotizacionControlador::class, 'store']);
    Route::get('/cotizaciones/{quote}', [CotizacionControlador::class, 'show']);

    Route::get('/reservas', [ReservaControlador::class, 'providerIndex']);
    Route::get('/reservas/{reservation}', [ReservaControlador::class, 'show']);
    Route::get('/pagos', [ProveedorControlador::class, 'payments']);
    Route::get('/comisiones', [ProveedorControlador::class, 'commissions']);
    Route::get('/notificaciones', [NotificacionControlador::class, 'index']);
});
