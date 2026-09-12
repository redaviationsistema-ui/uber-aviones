<?php

namespace App\Http\Intermediarios;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsIntermediario
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $allowOrigin = $this->resolveAllowedOrigin($origin);

        if ($origin && ! $allowOrigin) {
            return response()->json(['success' => false, 'message' => 'Origin not allowed.'], 403);
        }

        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Idempotency-Key',
            'Access-Control-Max-Age' => (string) env('CORS_MAX_AGE', 600),
            'Vary' => 'Origin',
        ];

        if ($allowOrigin) {
            $headers['Access-Control-Allow-Origin'] = $allowOrigin;
        }

        if ($request->getMethod() === 'OPTIONS') {
            return response('', 204, $headers);
        }

        $response = $next($request);

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }

    private function resolveAllowedOrigin(?string $origin): ?string
    {
        if (! $origin) {
            return null;
        }

        $allowedOrigins = collect(config('cors.allowed_origins', []))->reject(fn ($origin) => $origin === '*');

        return $allowedOrigins->contains($origin) ? $origin : null;
    }
}
