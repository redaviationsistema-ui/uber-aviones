<?php

use App\Http\Intermediarios\AsegurarAccesoPremium;
use App\Http\Intermediarios\FiltroAntiBrokerIntermediario;
use App\Http\Intermediarios\VerificarLimitePlanIntermediario;
use App\Http\Intermediarios\TokenApiIntermediario;
use App\Http\Intermediarios\AuditoriaIntermediario;
use App\Consola\Comandos\ExpirarCotizacionesComando;
use App\Consola\Comandos\ExpirarDemosComando;
use App\Consola\Comandos\ExpirarAircraftHoldsComando;
use App\Consola\Comandos\ExpirarSuscripcionesAeronavesComando;
use App\Consola\Comandos\ExpirarSuscripcionesComando;
use App\Consola\Comandos\LiberarPagosProveedorComando;
use App\Consola\Comandos\PerfilarFlotaAeronavesAdminComando;
use App\Consola\Comandos\NormalizarDatosLegacySobrecargoComando;
use App\Consola\Comandos\CrearOperacionSmokeSobrecargoComando;
use App\Consola\Comandos\EliminarOperacionSmokeSobrecargoComando;
use App\Consola\Comandos\EnviarRecordatoriosOperativosSobrecargoComando;
use App\Http\Intermediarios\VerificarAccesoActivo;
use App\Http\Intermediarios\VerificarDemo;
use App\Http\Intermediarios\VerificarProveedorAprobado;
use App\Http\Intermediarios\VerificarSuscripcion;
use App\Http\Intermediarios\ForzarRespuestaJson;
use App\Http\Intermediarios\CorsIntermediario;
use App\Http\Intermediarios\RolIntermediario;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if (! function_exists('agregarCabecerasCorsApi')) {
    function agregarCabecerasCorsApi(Request $request, \Illuminate\Http\JsonResponse $response): \Illuminate\Http\JsonResponse
    {
        if (! $request->is('api/*')) {
            return $response;
        }

        $origin = trim((string) $request->headers->get('Origin', ''));
        $allowedOrigins = collect(explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->values();

        if ($allowedOrigins->isEmpty()) {
            $allowedOrigins = collect([
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'https://redskyg.com',
                'https://www.redskyg.com',
                rtrim((string) env('APP_URL', ''), '/'),
            ])->filter()->values();
        }

        if ($origin !== '' && $allowedOrigins->contains($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }
}
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
         web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            RateLimiter::for('auth.login', function (Request $request) {
                return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
            });

            RateLimiter::for('auth.password-reset', function (Request $request) {
                return Limit::perMinute(3)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
            });

            RateLimiter::for('auth.password-reset-confirm', function (Request $request) {
                return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
            });

            RateLimiter::for('auth.email-verification', function (Request $request) {
                return Limit::perMinute(6)->by((string) $request->ip());
            });

            RateLimiter::for('auth.email-verification-notification', function (Request $request) {
                return Limit::perMinute(3)->by((string) ($request->user()?->id ?: $request->ip()));
            });
        },
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth.token']]
    )
    ->withCommands([
        ExpirarCotizacionesComando::class,
        ExpirarDemosComando::class,
        ExpirarAircraftHoldsComando::class,
        ExpirarSuscripcionesAeronavesComando::class,
        ExpirarSuscripcionesComando::class,
        LiberarPagosProveedorComando::class,
        PerfilarFlotaAeronavesAdminComando::class,
        NormalizarDatosLegacySobrecargoComando::class,
        CrearOperacionSmokeSobrecargoComando::class,
        EliminarOperacionSmokeSobrecargoComando::class,
        EnviarRecordatoriosOperativosSobrecargoComando::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorsIntermediario::class);
        $middleware->append(HandleCors::class);

        $middleware->alias([
            'auth.token' => TokenApiIntermediario::class,
            'audit.log' => AuditoriaIntermediario::class,
            'check.active.access' => VerificarAccesoActivo::class,
            'check.demo' => VerificarDemo::class,
            'check.provider.approved' => VerificarProveedorAprobado::class,
            'check.subscription' => VerificarSuscripcion::class,
            'force.json' => ForzarRespuestaJson::class,
            'role' => RolIntermediario::class,
            'premium' => AsegurarAccesoPremium::class,
            'anti_broker.filter' => FiltroAntiBrokerIntermediario::class,
            'operator.verified' => VerificarProveedorAprobado::class,
            'plan.limit' => VerificarLimitePlanIntermediario::class,
            'subscription.active' => VerificarAccesoActivo::class,
            'trial.active' => VerificarDemo::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return agregarCabecerasCorsApi($request, response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401));
            }

            return null;
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return agregarCabecerasCorsApi($request, response()->json([
                    'success' => false,
                    'message' => 'Datos invalidos.',
                    'errors' => $exception->errors(),
                ], $exception->status));
            }

            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;

            $message = trim($exception->getMessage());
            if ($message === '') {
                $message = $status >= 500
                    ? 'Error interno del servidor.'
                    : 'No se pudo procesar la solicitud.';
            }

            return agregarCabecerasCorsApi($request, response()->json([
                'success' => false,
                'message' => $message,
            ], $status));
        });
    })->create();
