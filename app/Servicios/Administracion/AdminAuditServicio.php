<?php

namespace App\Servicios\Administracion;

use App\Modelos\RegistroAuditoria;
use App\Modelos\Usuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use Illuminate\Support\Str;

class AdminAuditServicio
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'remember_token',
        'api_token',
        'secret',
        'stripe_secret',
        'stripe_webhook_secret',
        'app_key',
        'db_password',
        'smtp_password',
        'card_number',
        'card_last4',
        'cvc',
        'cvv',
        'bank_account',
        'account_number',
        'gateway_response',
    ];

    public function record(
        ?Usuario $actor,
        string $action,
        string $module,
        string $entity,
        mixed $entityId = null,
        mixed $before = null,
        mixed $after = null,
        ?string $reason = null,
        string $result = 'success',
        array $metadata = [],
        ?string $description = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $requestId = null,
    ): RegistroAuditoria {
        $sanitizedBefore = $this->sanitizeValue($before);
        $sanitizedAfter = $this->sanitizeValue($after);
        $sanitizedMetadata = $this->sanitizeValue($metadata);

        return RegistroAuditoria::query()->create([
            'admin_user_id' => $this->resolveAdminUserId($actor),
            'user_id' => $actor?->id,
            'action' => $action,
            'module' => $module,
            'entity' => $entity,
            'entity_id' => $entityId != null ? (string) $entityId : null,
            'reason' => $reason ? trim($reason) : null,
            'result' => trim($result) !== '' ? trim($result) : 'success',
            'before' => $sanitizedBefore,
            'after' => $sanitizedAfter,
            'metadata' => $sanitizedMetadata,
            'description' => $description,
            'old_values' => $sanitizedBefore,
            'new_values' => $sanitizedAfter,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'request_id' => $requestId,
        ]);
    }

    public function query(array $filters = []): LengthAwarePaginator
    {
        $query = RegistroAuditoria::query()
            ->with('user:id,name,email,role,operational_role')
            ->latest('id');

        $this->applyFilters($query, $filters);

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 50)));

        return $query->paginate($perPage);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when(($filters['admin_user_id'] ?? null) !== null, fn (Builder $scope) => $scope->where('admin_user_id', (int) $filters['admin_user_id']))
            ->when(filled($filters['action'] ?? null), fn (Builder $scope) => $scope->where('action', trim((string) $filters['action'])))
            ->when(filled($filters['module'] ?? null), fn (Builder $scope) => $scope->where('module', trim((string) $filters['module'])))
            ->when(filled($filters['entity'] ?? null), fn (Builder $scope) => $scope->where('entity', trim((string) $filters['entity'])))
            ->when(($filters['entity_id'] ?? null) !== null && $filters['entity_id'] !== '', fn (Builder $scope) => $scope->where('entity_id', (string) $filters['entity_id']))
            ->when(filled($filters['result'] ?? null), fn (Builder $scope) => $scope->where('result', trim((string) $filters['result'])))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $scope) => $scope->whereDate('created_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $scope) => $scope->whereDate('created_at', '<=', $filters['date_to']));
    }

    private function resolveAdminUserId(?Usuario $actor): ?int
    {
        if (! $actor) {
            return null;
        }

        if (($actor->role ?? null) === Usuario::ROLE_ADMIN || ($actor->operational_role ?? null) === Usuario::ROLE_ADMIN) {
            return $actor->id;
        }

        if (method_exists($actor, 'effectiveRole') && $actor->effectiveRole() === Usuario::ROLE_ADMIN) {
            return $actor->id;
        }

        if (! method_exists($actor, 'roles')) {
            return null;
        }

        try {
            $roleCodes = $actor->relationLoaded('roles')
                ? $actor->roles
                    ->pluck('code')
                    ->filter()
                    ->values()
                    ->all()
                : $actor->roles()
                    ->where('roles.code', Usuario::ROLE_ADMIN)
                    ->limit(1)
                    ->pluck('roles.code')
                    ->filter()
                    ->values()
                    ->all();
        } catch (Throwable) {
            return null;
        }

        return in_array(Usuario::ROLE_ADMIN, $roleCodes, true) ? $actor->id : null;
    }

    private function sanitizeValue(mixed $value, ?string $path = null): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $nextPath = $path ? "{$path}.{$key}" : (string) $key;
                $sanitized[$key] = $this->shouldMask((string) $key, $nextPath)
                    ? '[MASKED]'
                    : $this->sanitizeValue($item, $nextPath);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return $this->sanitizeValue((array) $value, $path);
        }

        if (! is_string($value)) {
            return $value;
        }

        return $this->shouldMask((string) Str::of($path ?: '')->afterLast('.'), $path)
            ? '[MASKED]'
            : $value;
    }

    private function shouldMask(string $key, ?string $path = null): bool
    {
        $normalizedKey = Str::of($key)->trim()->lower()->replace(['-', ' '], '_')->value();
        $normalizedPath = Str::of((string) $path)->trim()->lower()->replace(['-', ' '], '_')->value();

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if ($normalizedKey === $sensitiveKey || Str::contains($normalizedKey, $sensitiveKey)) {
                return true;
            }

            if ($normalizedPath !== '' && Str::contains($normalizedPath, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
