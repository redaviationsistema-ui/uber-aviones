<?php

use Illuminate\Support\Facades\Route;

Route::get('/debug/aws', function () {
    return response()->json([
        'getenv_access' => getenv('AWS_ACCESS_KEY_ID') ? 'OK' : 'FALTA',
        'getenv_secret' => getenv('AWS_SECRET_ACCESS_KEY') ? 'OK' : 'FALTA',
        'env_access' => env('AWS_ACCESS_KEY_ID') ? 'OK' : 'FALTA',
        'env_secret' => env('AWS_SECRET_ACCESS_KEY') ? 'OK' : 'FALTA',
        'config_key' => config('filesystems.disks.s3.key') ? 'OK' : 'FALTA',
        'config_secret' => config('filesystems.disks.s3.secret') ? 'OK' : 'FALTA',
        'bucket' => config('filesystems.disks.s3.bucket'),
        'region' => config('filesystems.disks.s3.region'),
    ]);
});

Route::prefix('v1')->group(function () {
    require __DIR__.'/api_v1_autenticacion.php';
    require __DIR__.'/api_v1_publico.php';
    require __DIR__.'/api_v1_cliente.php';
    require __DIR__.'/api_v1_proveedor.php';
    require __DIR__.'/api_v1_red_aviation.php';
    require __DIR__.'/api_v1_admin.php';
    require __DIR__.'/api_v1_pasarelas.php';
});
