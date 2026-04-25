<?php

use Illuminate\Support\Facades\Route;

Route::prefix('webhooks')->group(function () {
    Route::post('/stripe', fn () => response()->json(['success' => true, 'provider' => 'stripe']));
    Route::post('/openpay', fn () => response()->json(['success' => true, 'provider' => 'openpay']));
    Route::post('/mercadopago', fn () => response()->json(['success' => true, 'provider' => 'mercadopago']));
});
