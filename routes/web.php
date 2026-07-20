<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return response()->json([
        'message' => 'API funcionando',
    ]);
});

Route::get('/health', function () {
    $mailMailer = strtolower(trim((string) config('mail.default', '')));
    $blockedMailers = collect(config('mail.production_blocked_mailers', ['log', 'array']))
        ->map(fn ($mailer) => strtolower(trim((string) $mailer)))
        ->filter()
        ->values()
        ->all();
    $checks = [];
    try {
        DB::select('select 1');
        $checks['database'] = ['ready' => true];
    } catch (Throwable $error) {
        report($error);
        $checks['database'] = ['ready' => false];
    }

    try {
        Storage::disk(config('filesystems.default'))->exists('.healthcheck');
        $checks['storage'] = ['ready' => true];
    } catch (Throwable $error) {
        report($error);
        $checks['storage'] = ['ready' => false];
    }

    $mailReady = ! (app()->environment('production') && in_array($mailMailer, $blockedMailers, true));
    $checks['mail'] = ['ready' => $mailReady, 'mailer' => $mailMailer];
    $ready = collect($checks)->every(fn (array $check): bool => $check['ready']);

    if (! $mailReady && ! app()->runningUnitTests()) {
        Log::error('Mail production readiness check failed.', [
            'mail_mailer' => $mailMailer,
            'blocked_mailers' => $blockedMailers,
            'environment' => app()->environment(),
        ]);
    }

    return response()->json([
        'status' => $ready ? 'ok' : 'error',
        'app' => config('app.name'),
        'environment' => app()->environment(),
        'checks' => $checks,
    ], $ready ? 200 : 503);
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

if (! app()->environment('production')) {
    Route::get('/debug-cloudinary', function () {
        return response()->json([
            'configured' => filled(config('services.cloudinary.cloud_name'))
                && filled(config('services.cloudinary.api_key'))
                && filled(config('services.cloudinary.api_secret')),
        ]);
    });
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
