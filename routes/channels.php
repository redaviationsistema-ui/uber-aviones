<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('provider.{providerId}', function ($user, int $providerId): bool {
    $resolvedProviderId = method_exists($user, 'resolvedProviderId')
        ? $user->resolvedProviderId()
        : ($user->provider_id ?? null);

    return (int) $resolvedProviderId === (int) $providerId;
});
