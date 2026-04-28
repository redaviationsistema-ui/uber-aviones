<?php

use App\Http\Controladores\RedAviation\AdminControlador;
use App\Http\Controladores\RedAviation\ChatControlador;
use App\Http\Controladores\RedAviation\ClienteControlador;
use App\Http\Controladores\RedAviation\OperadorControlador;
use App\Http\Controladores\RedAviation\PlanControlador;
use App\Http\Controladores\RedAviation\SobrecargoControlador;
use App\Http\Controladores\RedAviation\SuscripcionControlador;
use App\Http\Controladores\NotificacionControlador;
use Illuminate\Support\Facades\Route;

Route::get('/plans', [PlanControlador::class, 'index']);

Route::middleware(['auth.token'])->group(function () {
    Route::post('/subscriptions/start-trial', [SuscripcionControlador::class, 'startTrial']);
    Route::post('/subscriptions/checkout', [SuscripcionControlador::class, 'checkout']);
    Route::get('/subscriptions/current', [SuscripcionControlador::class, 'current']);
    Route::post('/subscriptions/cancel', [SuscripcionControlador::class, 'cancel']);

    Route::prefix('client')->middleware(['role:client,admin', 'subscription.active'])->group(function () {
        Route::get('/dashboard', [ClienteControlador::class, 'dashboard']);
        Route::post('/flight-requests', [ClienteControlador::class, 'storeFlightRequest'])->middleware('plan.limit');
        Route::get('/flight-requests', [ClienteControlador::class, 'indexFlightRequests']);
        Route::get('/flight-requests/{flightRequest}', [ClienteControlador::class, 'showFlightRequest']);
        Route::get('/operations/{operation}/tracking', [ClienteControlador::class, 'tracking']);
    });

    Route::prefix('operator')->middleware(['role:provider,admin', 'operator.verified'])->group(function () {
        Route::get('/dashboard', [OperadorControlador::class, 'dashboard']);
        Route::post('/aircraft', [OperadorControlador::class, 'storeAircraft']);
        Route::get('/aircraft', [OperadorControlador::class, 'indexAircraft']);
        Route::put('/aircraft/{aircraft}', [OperadorControlador::class, 'updateAircraft']);
        Route::post('/availability', [OperadorControlador::class, 'storeAvailability']);
        Route::get('/requests', [OperadorControlador::class, 'requests']);
        Route::post('/requests/{flightRequest}/accept', [OperadorControlador::class, 'accept']);
        Route::post('/requests/{flightRequest}/reject', [OperadorControlador::class, 'reject']);
    });

    Route::prefix('sobrecargo')->middleware(['role:sobrecargo,admin'])->group(function () {
        Route::get('/dashboard', [SobrecargoControlador::class, 'dashboard']);
        Route::get('/assignments', [SobrecargoControlador::class, 'assignments']);
        Route::get('/operations/{operation}', [SobrecargoControlador::class, 'operation']);
        Route::post('/checklists/{checklist}/complete', [SobrecargoControlador::class, 'completeChecklist']);
        Route::post('/incidents', [SobrecargoControlador::class, 'incidents']);
    });

    Route::prefix('admin')->middleware(['role:admin'])->group(function () {
        Route::get('/dashboard', [AdminControlador::class, 'dashboard']);
        Route::get('/users', [AdminControlador::class, 'users']);
        Route::get('/operators', [AdminControlador::class, 'operators']);
        Route::get('/sobrecargos', [AdminControlador::class, 'sobrecargos']);
        Route::get('/requests', [AdminControlador::class, 'requests']);
        Route::post('/requests/{flightRequest}/assign', [AdminControlador::class, 'assign']);
        Route::get('/subscriptions', [AdminControlador::class, 'subscriptions']);
        Route::get('/kpis', [AdminControlador::class, 'kpis']);
        Route::get('/anti-broker-flags', [AdminControlador::class, 'antiBrokerFlags']);
    });

    Route::get('/chats/{chat}', [ChatControlador::class, 'show']);
    Route::post('/chats/{chat}/messages', [ChatControlador::class, 'storeMessage'])->middleware('anti_broker.filter');

    Route::get('/notifications', [NotificacionControlador::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificacionControlador::class, 'markAsRead']);
});
