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

        $token = TokenApi::with([
                'user.roles',
                'user.demo',
                'user.activeSuscripcion.plan',
                'user.profile',
                'user.provider',
            ])
            ->where('token', hash('sha256', $plainToken))
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $token || ! $token->user || $token->user->status !== 'active') {
            return $this->unauthenticated();
        }

        if ($this->shouldRefreshLastUsedAt($token->last_used_at)) {
            $token->timestamps = false;
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }

    private function authCookieName(): string
    {
        return (string) env('AUTH_TOKEN_COOKIE', 'red_aviation_session');
    }

    private function shouldRefreshLastUsedAt($lastUsedAt): bool
    {
        $refreshEveryMinutes = max((int) env('AUTH_TOKEN_REFRESH_MINUTES', 5), 1);

        return $lastUsedAt === null
            || $lastUsedAt->lte(now()->subMinutes($refreshEveryMinutes));
    }

    private function unauthenticated(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }
}
