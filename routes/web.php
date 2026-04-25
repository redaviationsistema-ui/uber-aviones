<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return response()->json([
        'message' => 'API funcionando',
    ]);
});

Route::get('/public/storage/{path}', function (string $path) {
    $path = ltrim(str_replace('\\', '/', $path), '/');

    abort_unless(Storage::disk('public')->exists($path), 404);

    return Storage::disk('public')->response($path);
})->where('path', '.*');

Route::get('/storage/{path}', function (string $path) {
    $path = ltrim(str_replace('\\', '/', $path), '/');

    abort_unless(Storage::disk('public')->exists($path), 404);

    return Storage::disk('public')->response($path);
})->where('path', '.*');

Route::get('/debug-cloudinary', function () {
    return response()->json([
        'cloud_name_configured' => filled(config('services.cloudinary.cloud_name')),
        'api_key_configured' => filled(config('services.cloudinary.api_key')),
        'api_secret_configured' => filled(config('services.cloudinary.api_secret')),
        'folder' => config('services.cloudinary.folder'),
        'active' => filled(config('services.cloudinary.cloud_name'))
            && filled(config('services.cloudinary.api_key'))
            && filled(config('services.cloudinary.api_secret')),
        'app_url' => config('app.url'),
        'environment' => app()->environment(),
    ]);
});

if (! app()->environment('production')) {
    Route::get('/debug-imagen', function () {
        $path = 'discrepancias/1544ca2b-3983-4a70-8d0e-dc34802df65f.png';

        return response()->json([
            'path_bd' => $path,
            'exists_public_disk' => Storage::disk('public')->exists($path),
            'storage_real' => storage_path('app/public/' . $path),
            'public_path' => public_path('storage/' . $path),
            'url_publica' => asset('public/storage/' . $path),
        ]);
    });
}
