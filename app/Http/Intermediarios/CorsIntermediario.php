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

        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept',
            'Access-Control-Max-Age' => (string) env('CORS_MAX_AGE', 600),
            'Vary' => 'Origin',
        ];

        if ($allowOrigin) {
            $headers['Access-Control-Allow-Origin'] = $allowOrigin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
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

        return $allowedOrigins->contains($origin) ? $origin : null;
    }
}
