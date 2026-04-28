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
    public const ROLE_SOBRECARGO = 'sobrecargo';
    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'operational_role',
        'status',
        'contact_strikes',
        'contact_blocked_until',
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
            'contact_blocked_until' => 'datetime',
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
            'effective_role' => $this->effectiveRole(),
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

    public function effectiveRole(): string
    {
        return $this->operational_role ?: $this->role;
    }

    public function isRole(string ...$roles): bool
    {
        return in_array($this->effectiveRole(), $roles, true) || in_array($this->role, $roles, true);
    }

    public function dashboardPath(): string
    {
        return match ($this->effectiveRole()) {
            self::ROLE_ADMIN => '/admin/dashboard',
            self::ROLE_PROVIDER => '/operator/dashboard',
            self::ROLE_SOBRECARGO => '/sobrecargo/dashboard',
            default => '/client/dashboard',
        };
    }

    public function resolvedPlanId(): ?int
    {
        return $this->activeSuscripcion?->plan_id;
    }

    public function resolvedSubscriptionStatus(): string
    {
        $demo = $this->demo;
        $subscription = $this->activeSuscripcion;

        if ($demo?->status === 'active' && $demo->expires_at?->isFuture()) {
            return 'demo_activa';
        }

        if ($subscription) {
            return match ($subscription->status) {
                'active' => 'activa',
                'expired' => 'vencida',
                'cancelled' => 'cancelada',
                'past_due' => 'renovacion_pendiente',
                default => $subscription->status,
            };
        }

        return 'sin_suscripcion';
    }

    public function loginContext(): array
    {
        return [
            'role' => $this->role,
            'operational_role' => $this->operational_role,
            'effective_role' => $this->effectiveRole(),
            'status' => $this->status,
            'subscription_status' => $this->resolvedSubscriptionStatus(),
            'plan_id' => $this->resolvedPlanId(),
            'dashboard' => $this->dashboardPath(),
        ];
    }
}
