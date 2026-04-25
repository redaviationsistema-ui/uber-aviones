<?php

namespace App\Http\Intermediarios;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        $demo = $request->user()?->demo;

        if (! $demo || $demo->status !== 'active' || $demo->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Demo no activa.',
            ], 402);
        }

        return $next($request);
    }
}
