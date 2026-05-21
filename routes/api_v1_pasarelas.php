<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controladores\StripeWebhookControlador;

Route::prefix('webhooks')->group(function () {
    Route::post('/stripe', [StripeWebhookControlador::class, 'handle']);
    Route::post('/openpay', fn () => response()->json(['success' => true, 'provider' => 'openpay']));
    Route::post('/mercadopago', fn () => response()->json(['success' => true, 'provider' => 'mercadopago']));
});

Route::post('/stripe/webhook', [StripeWebhookControlador::class, 'handle']);
