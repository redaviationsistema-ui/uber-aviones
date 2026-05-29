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
    Route::get('/mi-dashboard', [ProveedorControlador::class, 'dashboard']);
    Route::get('/empresa', [ProveedorControlador::class, 'company']);
    Route::put('/empresa', [ProveedorControlador::class, 'updateCompany']);
    Route::post('/empresa/documentos', [ProveedorControlador::class, 'storeCompanyDocument']);
    Route::post('/empresa/enviar-revision', [ProveedorControlador::class, 'submitCompanyReview']);
    Route::get('/perfil', [AutenticacionControlador::class, 'me']);
    Route::put('/perfil', [AutenticacionControlador::class, 'updatePerfil']);

    Route::get('/mis-aeronaves', [AeronaveControlador::class, 'index']);
    Route::apiResource('aeronaves', AeronaveControlador::class)
        ->parameters(['aeronaves' => 'aircraft']);
    Route::get('/aeronaves/{aircraft}/imagenes', [AeronaveControlador::class, 'images']);
    Route::post('/aeronaves/{aircraft}/imagenes', [AeronaveControlador::class, 'storeImage']);
    Route::post('/aeronaves/{aircraft}/imagenes/reasociar', [AeronaveControlador::class, 'attachExistingImage']);
    Route::delete('/aeronaves/{aircraft}/imagenes/{image}', [AeronaveControlador::class, 'destroyImage']);
    Route::post('/aeronaves/{aircraft}/documentos', [AeronaveControlador::class, 'storeDocument']);
    Route::get('/aeronaves/{aircraft}/documentos/{document}/descargar', [AeronaveControlador::class, 'downloadDocument']);
    Route::delete('/aeronaves/{aircraft}/documentos/{document}', [AeronaveControlador::class, 'destroyDocument']);

    Route::get('/disponibilidad', [AeronaveControlador::class, 'availability']);
    Route::post('/disponibilidad', [AeronaveControlador::class, 'storeAvailability']);
    Route::put('/disponibilidad/{availability}', [AeronaveControlador::class, 'updateAvailability']);
    Route::delete('/disponibilidad/{availability}', [AeronaveControlador::class, 'destroyAvailability']);

    Route::get('/mis-solicitudes', [ProveedorControlador::class, 'requests']);
    Route::get('/solicitudes', [ProveedorControlador::class, 'requests']);
    Route::get('/solicitudes/{flightRequest}', [ProveedorControlador::class, 'showRequest']);
    Route::post('/solicitudes/{flightRequest}/aceptar', [ProveedorControlador::class, 'acceptRequest']);
    Route::post('/solicitudes/{flightRequest}/rechazar', [ProveedorControlador::class, 'rejectRequest']);
    Route::put('/solicitudes/{flightRequest}/release-provider', [ProveedorControlador::class, 'updateReleaseProvider']);

    Route::get('/cotizaciones', [CotizacionControlador::class, 'providerIndex']);
    Route::post('/cotizaciones', [CotizacionControlador::class, 'store']);
    Route::get('/cotizaciones/{quote}', [CotizacionControlador::class, 'show']);

    Route::get('/reservas', [ReservaControlador::class, 'providerIndex']);
    Route::get('/reservas/{reservation}', [ReservaControlador::class, 'show']);
    Route::get('/tripulacion', [ProveedorControlador::class, 'crew']);
    Route::get('/operaciones', [ProveedorControlador::class, 'operations']);
    Route::put('/operaciones/{operation}', [ProveedorControlador::class, 'updateOperation']);
    Route::get('/incidencias', [ProveedorControlador::class, 'incidents']);
    Route::post('/incidencias', [ProveedorControlador::class, 'storeIncident']);
    Route::put('/incidencias/{timeline}', [ProveedorControlador::class, 'updateIncident']);
    Route::get('/pagos', [ProveedorControlador::class, 'payments']);
    Route::get('/configuracion', [ProveedorControlador::class, 'settings']);
    Route::put('/configuracion', [ProveedorControlador::class, 'updateSettings']);
    Route::get('/comisiones', [ProveedorControlador::class, 'commissions']);
    Route::get('/historial', [ProveedorControlador::class, 'history']);
    Route::get('/notificaciones', [NotificacionControlador::class, 'index']);
});

Route::prefix('provider')->middleware(['auth.token', 'role:provider,admin'])->group(function () {
    Route::post('/aircraft/{aircraft}/documents', [AeronaveControlador::class, 'storeDocument']);
    Route::get('/aircraft/{aircraft}/documents/{document}/download', [AeronaveControlador::class, 'downloadDocument']);
    Route::delete('/aircraft/{aircraft}/documents/{document}', [AeronaveControlador::class, 'destroyDocument']);
});
