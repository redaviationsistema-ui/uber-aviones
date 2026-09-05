<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notificacion extends Model
{
    protected $table = 'notifications';

    protected $fillable = ['user_id', 'provider_id', 'type', 'title', 'message', 'payload', 'data', 'read_at', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, Usuario $user): void
    {
        $providerId = $user->resolvedProviderId();
        $query->where(function ($visible) use ($user, $providerId) {
            $visible->where(function ($personal) use ($user) {
                $personal->where('user_id', $user->id)
                    ->where(function ($legacy) {
                        $legacy->whereNull('idempotency_key')->orWhere('idempotency_key', 'not like', 'provider:%');
                    });
            });
            if ($providerId) {
                $visible->orWhere(function ($shared) use ($providerId) {
                    $shared->where('provider_id', $providerId)
                        ->where('idempotency_key', 'like', 'provider:'.$providerId.':flight:%')
                        ->whereIn('type', \App\Servicios\RedAviation\ProviderFlightNotificationService::TYPES);
                });
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
