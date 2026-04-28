<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__.'/api_v1_autenticacion.php';
    require __DIR__.'/api_v1_publico.php';
    require __DIR__.'/api_v1_cliente.php';
    require __DIR__.'/api_v1_proveedor.php';
    require __DIR__.'/api_v1_red_aviation.php';
    require __DIR__.'/api_v1_admin.php';
    require __DIR__.'/api_v1_pasarelas.php';
});
