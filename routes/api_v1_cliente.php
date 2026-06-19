<?php

use App\Http\Controladores\AutenticacionControlador;
use App\Http\Controladores\AeronaveControlador;
use App\Http\Controladores\BiometricControlador;
use App\Http\Controladores\DemoControlador;
use App\Http\Controladores\SolicitudVueloControlador;
use App\Http\Controladores\NotificacionControlador;
use App\Http\Controladores\PagoControlador;
use App\Http\Controladores\MetodoPagoControlador;
use App\Http\Controladores\CotizacionControlador;
use App\Http\Controladores\ReservaControlador;
use App\Http\Controladores\StripePagoControlador;
use App\Http\Controladores\SuscripcionControlador;
use Illuminate\Support\Facades\Route;

Route::prefix('cliente')->middleware(['auth.token', 'role:client,admin'])->group(function () {
    Route::get('/dashboard', [SuscripcionControlador::class, 'status']);
    Route::get('/perfil', [AutenticacionControlador::class, 'me']);
    Route::put('/perfil', [AutenticacionControlador::class, 'updatePerfil']);
    Route::post('/biometric/detect-face', [BiometricControlador::class, 'detectFace']);

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
    Route::get('/reservas/{reservation}/contrato', [ReservaControlador::class, 'showContract']);
    Route::get('/reservas/{reservation}/contrato/pdf', [ReservaControlador::class, 'downloadContractPdf']);
    Route::post('/reservas/{reservation}/contrato/generar', [ReservaControlador::class, 'generateContract']);
    Route::post('/reservas/{reservation}/contrato/docusign', [ReservaControlador::class, 'startEmbeddedSigning']);
    Route::post('/reservas/{reservation}/contrato/firmar', [ReservaControlador::class, 'signContract']);
    Route::get('/contratos/{contract}/estado', [ReservaControlador::class, 'showContractStatusById'])
        ->name('cliente.contratos.estado');
    Route::get('/contratos/{contract}/pdf-firmado', [ReservaControlador::class, 'downloadSignedContractPdf'])
        ->name('cliente.contratos.pdf-firmado');
    Route::post('/reservas/{reservation}/calificar', [ReservaControlador::class, 'rateService']);
    Route::post('/reservas/{reservation}/pagar', [PagoControlador::class, 'storeReservaPago']);
    Route::post('/reservas/{reservation}/pago/confirmar', [StripePagoControlador::class, 'confirmReservationPayment']);
    Route::post('/stripe/payment-intent/confirm', [StripePagoControlador::class, 'confirmFlightRequestPayment']);
    Route::post('/reservas/{reservation}/reintentar-pago', [PagoControlador::class, 'retryReservaPago']);
    Route::post('/stripe/checkout/create', [StripePagoControlador::class, 'createCheckout']);
    Route::post('/stripe/payment-intent', [StripePagoControlador::class, 'createPaymentIntent']);
    Route::post('/stripe/wire-intent', [StripePagoControlador::class, 'createWireIntent']);

    Route::get('/pagos', [PagoControlador::class, 'index']);
    Route::get('/historial', [SolicitudVueloControlador::class, 'history']);
    Route::get('/notificaciones', [NotificacionControlador::class, 'index']);
    Route::put('/notificaciones/{notification}/leer', [NotificacionControlador::class, 'markAsRead']);

    Route::apiResource('metodos-pago', MetodoPagoControlador::class)
        ->parameters(['metodos-pago' => 'paymentMethod'])
        ->only(['index', 'store', 'destroy']);
});

Route::prefix('stripe')->middleware(['auth.token', 'role:client,admin'])->group(function () {
    Route::post('/payment-intent/confirm', [StripePagoControlador::class, 'confirmFlightRequestPayment']);
    Route::post('/checkout/create', [StripePagoControlador::class, 'createCheckout']);
    Route::post('/payment-intent', [StripePagoControlador::class, 'createPaymentIntent']);
    Route::post('/wire-intent', [StripePagoControlador::class, 'createWireIntent']);
});
