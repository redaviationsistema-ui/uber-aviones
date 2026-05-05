<?php

namespace App\Http\Intermediarios;

use App\Modelos\TokenApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TokenApiIntermediario
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken() ?: $request->cookie($this->authCookieName());

        if (! $plainToken) {
            return $this->unauthenticated();
        }

        $token = TokenApi::with('user')
            ->where('token', hash('sha256', $plainToken))
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $token || ! $token->user || $token->user->status !== 'active') {
            return $this->unauthenticated();
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }

    private function authCookieName(): string
    {
        return (string) env('AUTH_TOKEN_COOKIE', 'red_aviation_session');
    }

    private function unauthenticated(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }
}
