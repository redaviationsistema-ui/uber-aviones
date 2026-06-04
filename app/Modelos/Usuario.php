<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class Usuario extends Authenticatable
{
    protected $table = 'users';

    use HasFactory;
    use Notifiable;

    public const ROLE_CLIENT = 'client';
    public const ROLE_PROVIDER = 'provider';
    public const ROLE_SOBRECARGO = 'sobrecargo';
    public const ROLE_ADMIN = 'admin';
    public const BASE_ROLES = [
        self::ROLE_CLIENT,
        self::ROLE_PROVIDER,
        self::ROLE_ADMIN,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'operational_role',
        'provider_id',
        'status',
        'contact_strikes',
        'contact_blocked_until',
        'email_verified_at',
        'identity_verification_status',
        'identity_verification_message',
        'identity_verified',
        'face_detected',
        'face_match_score',
        'liveness_score',
        'image_storage_score',
        'biometric_image_saved',
        'biometric_captured_at',
        'biometric_provider',
        'biometric_template_type',
        'biometric_selfie_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'proveedor_id',
        'access',
        'subscription_status',
        'effective_role',
        'biometric_selfie_url',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'contact_blocked_until' => 'datetime',
            'identity_verified' => 'boolean',
            'face_detected' => 'boolean',
            'face_match_score' => 'decimal:2',
            'liveness_score' => 'decimal:2',
            'image_storage_score' => 'decimal:2',
            'biometric_image_saved' => 'boolean',
            'biometric_captured_at' => 'datetime',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Perfil::class, 'user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'user_roles', 'user_id', 'role_id')
            ->withPivot(['is_primary', 'assigned_at'])
            ->withTimestamps();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function ownedProvider(): HasOne
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

    public function identityVerifications(): HasMany
    {
        return $this->hasMany(IdentityVerification::class, 'user_id');
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
            'has_access' => $demoActive || $subscriptionActive || $this->hasRole(self::ROLE_ADMIN),
            'effective_role' => $this->effectiveRole(),
            'roles' => $this->roleCodes(),
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
        return $this->primaryRoleCode() ?: ($this->operational_role ?: $this->role);
    }

    public function isRole(string ...$roles): bool
    {
        return $this->hasRole(...$roles);
    }

    public function hasRole(string ...$roles): bool
    {
        $assignedRoles = $this->roleCodes();

        foreach ($roles as $role) {
            if (in_array($role, $assignedRoles, true)) {
                return true;
            }
        }

        return false;
    }

    public function roleCodes(): array
    {
        if ($this->relationLoaded('roles')) {
            $codes = $this->roles
                ->pluck('code')
                ->filter(fn ($code) => is_string($code) && $code !== '')
                ->values()
                ->all();
        } else {
            $codes = $this->roles()
                ->pluck('roles.code')
                ->filter(fn ($code) => is_string($code) && $code !== '')
                ->values()
                ->all();
        }

        if ($codes !== []) {
            return array_values(array_unique($codes));
        }

        return array_values(array_unique(array_filter([$this->role, $this->operational_role])));
    }

    public function primaryRoleCode(): ?string
    {
        if ($this->relationLoaded('roles')) {
            $primary = $this->roles->firstWhere('pivot.is_primary', true);

            return $primary?->code;
        }

        return $this->roles()
            ->wherePivot('is_primary', true)
            ->value('roles.code');
    }

    public function syncRoles(array $roleCodes, ?string $primaryRoleCode = null): void
    {
        $roleCodes = collect($roleCodes)
            ->filter(fn ($code) => is_string($code) && $code !== '')
            ->unique()
            ->values();

        if ($roleCodes->isEmpty()) {
            $roleCodes = collect([self::ROLE_CLIENT]);
        }

        $primaryRoleCode = $primaryRoleCode && $roleCodes->contains($primaryRoleCode)
            ? $primaryRoleCode
            : ($roleCodes->contains(self::ROLE_CLIENT) ? self::ROLE_CLIENT : $roleCodes->first());

        $roles = Rol::query()
            ->whereIn('code', $roleCodes->all())
            ->pluck('id', 'code');

        $syncPayload = [];

        foreach ($roleCodes as $code) {
            if (! isset($roles[$code])) {
                continue;
            }

            $syncPayload[$roles[$code]] = [
                'is_primary' => $code === $primaryRoleCode,
                'assigned_at' => now(),
            ];
        }

        if ($syncPayload === []) {
            return;
        }

        $this->roles()->sync($syncPayload);
        $this->syncLegacyRoleColumns($roleCodes->all(), $primaryRoleCode);
        $this->unsetRelation('roles');
    }

    private function syncLegacyRoleColumns(array $roleCodes, string $primaryRoleCode): void
    {
        $roleCodes = array_values(array_unique($roleCodes));
        $baseRole = collect([self::ROLE_ADMIN, self::ROLE_PROVIDER, self::ROLE_CLIENT])
            ->first(fn ($role) => in_array($role, $roleCodes, true))
            ?? (in_array($primaryRoleCode, self::BASE_ROLES, true) ? $primaryRoleCode : self::ROLE_CLIENT);

        $operationalRole = ! in_array($primaryRoleCode, self::BASE_ROLES, true)
            ? $primaryRoleCode
            : collect($roleCodes)->first(fn ($role) => ! in_array($role, self::BASE_ROLES, true));

        $this->forceFill([
            'role' => $baseRole,
            'operational_role' => $operationalRole,
        ])->saveQuietly();
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
            'roles' => $this->roleCodes(),
            'provider_id' => $this->resolvedProviderId(),
            'proveedor_id' => $this->resolvedProviderId(),
            'status' => $this->status,
            'subscription_status' => $this->resolvedSubscriptionStatus(),
            'plan_id' => $this->resolvedPlanId(),
            'dashboard' => $this->dashboardPath(),
        ];
    }

    public function getAccessAttribute(): array
    {
        return $this->accessStatus();
    }

    public function getSubscriptionStatusAttribute(): string
    {
        return $this->resolvedSubscriptionStatus();
    }

    public function getEffectiveRoleAttribute(): string
    {
        return $this->effectiveRole();
    }

    public function getProveedorIdAttribute(): ?int
    {
        return $this->resolvedProviderId();
    }

    public function getBiometricSelfieUrlAttribute(): ?string
    {
        if (! $this->biometric_selfie_path) {
            return null;
        }

        return Storage::disk('public')->url($this->biometric_selfie_path);
    }

    public function resolvedProviderId(): ?int
    {
        if ($this->provider_id) {
            return (int) $this->provider_id;
        }

        if ($this->relationLoaded('ownedProvider')) {
            return $this->ownedProvider?->id;
        }

        return $this->ownedProvider()->value('id');
    }
}
