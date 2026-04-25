<?php

use App\Http\Controladores\AutenticacionControlador;
use App\Http\Controladores\AeronaveControlador;
use App\Http\Controladores\DemoControlador;
use App\Http\Controladores\SolicitudVueloControlador;
use App\Http\Controladores\NotificacionControlador;
use App\Http\Controladores\PagoControlador;
use App\Http\Controladores\MetodoPagoControlador;
use App\Http\Controladores\CotizacionControlador;
use App\Http\Controladores\ReservaControlador;
use App\Http\Controladores\SuscripcionControlador;
use Illuminate\Support\Facades\Route;

Route::prefix('cliente')->middleware(['auth.token', 'role:client,admin'])->group(function () {
    Route::get('/dashboard', [SuscripcionControlador::class, 'status']);
    Route::get('/perfil', [AutenticacionControlador::class, 'me']);
    Route::put('/perfil', [AutenticacionControlador::class, 'updatePerfil']);

    Route::post('/demo/activar', [DemoControlador::class, 'activate']);
    Route::get('/demo/estado', [SuscripcionControlador::class, 'status']);

    Route::get('/suscripcion/estado', [SuscripcionControlador::class, 'status']);
    Route::post('/suscripcion/contratar', [SuscripcionControlador::class, 'subscribe']);
    Route::post('/suscripcion/cancelar', [SuscripcionControlador::class, 'cancel']);

    Route::post('/buscar-vuelo', [AeronaveControlador::class, 'search']);
    Route::apiResource('solicitudes', SolicitudVueloControlador::class)
        ->parameters(['solicitudes' => 'flightRequest'])
        ->only(['index', 'store', 'show']);

    Route::get('/cotizaciones', [CotizacionControlador::class, 'index']);
    Route::get('/cotizaciones/{quote}', [CotizacionControlador::class, 'show']);
    Route::post('/cotizaciones/{quote}/aceptar', [CotizacionControlador::class, 'accept']);
    Route::post('/cotizaciones/{quote}/rechazar', [CotizacionControlador::class, 'reject']);

    Route::get('/reservas', [ReservaControlador::class, 'index']);
    Route::get('/reservas/{reservation}', [ReservaControlador::class, 'show']);
    Route::post('/reservas', [ReservaControlador::class, 'store'])->middleware('premium');
    Route::post('/reservas/{reservation}/pagar', [PagoControlador::class, 'storeReservaPago']);

    Route::get('/pagos', [PagoControlador::class, 'index']);
    Route::get('/historial', [SolicitudVueloControlador::class, 'history']);
    Route::get('/notificaciones', [NotificacionControlador::class, 'index']);
    Route::put('/notificaciones/{notification}/leer', [NotificacionControlador::class, 'markAsRead']);

    Route::apiResource('metodos-pago', MetodoPagoControlador::class)
        ->parameters(['metodos-pago' => 'paymentMethod'])
        ->only(['index', 'store', 'destroy']);
});
