<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Usuario extends Authenticatable
{
    protected $table = 'users';

    use HasFactory;
    use Notifiable;

    public const ROLE_CLIENT = 'client';
    public const ROLE_PROVIDER = 'provider';
    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'status',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Perfil::class, 'user_id');
    }

    public function provider(): HasOne
    {
        return $this->hasOne(Proveedor::class, 'user_id');
    }

    public function demo(): HasOne
    {
        return $this->hasOne(Demo::class, 'user_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Suscripcion::class, 'user_id');
    }

    public function activeSuscripcion(): HasOne
    {
        return $this->hasOne(Suscripcion::class, 'user_id')
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->latestOfMany();
    }

    public function flightRequests(): HasMany
    {
        return $this->hasMany(SolicitudVuelo::class, 'client_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reserva::class, 'client_id');
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(MetodoPago::class, 'user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notificacion::class, 'user_id');
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(TokenApi::class, 'user_id');
    }

    public function hasPremiumAccess(): bool
    {
        return $this->accessStatus()['has_access'];
    }

    public function accessStatus(): array
    {
        $demo = $this->demo;
        $subscription = $this->activeSuscripcion;
        $demoActive = $demo?->status === 'active' && $demo->expires_at?->isFuture();
        $subscriptionActive = $subscription !== null;

        return [
            'has_access' => $demoActive || $subscriptionActive || $this->role === self::ROLE_ADMIN,
            'demo' => $demo ? [
                'status' => $demoActive ? 'active' : 'expired',
                'started_at' => $demo->started_at,
                'expires_at' => $demo->expires_at,
                'days_left' => $demoActive ? now()->diffInDays($demo->expires_at, false) : 0,
            ] : null,
            'subscription' => $subscription ? [
                'status' => 'active',
                'plan_id' => $subscription->plan_id,
                'started_at' => $subscription->started_at,
                'expires_at' => $subscription->expires_at,
            ] : null,
        ];
    }
}
