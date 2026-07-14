<?php

namespace App\Http\Controladores;

use App\Modelos\RegistroAuditoria;
use App\Modelos\Usuario;
use App\Servicios\Administracion\AdminAuditServicio;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class ControladorBase
{
    protected function canonicalizeFieldName(string $field): string
    {
        $trimmedField = trim($field);
        $withSnakeCaseBoundaries = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $trimmedField) ?? $trimmedField;
        $withUnderscores = preg_replace('/[\s\-]+/', '_', $withSnakeCaseBoundaries) ?? $withSnakeCaseBoundaries;
        $collapsedSeparators = preg_replace('/_+/', '_', $withUnderscores) ?? $withUnderscores;

        return strtolower(trim($collapsedSeparators, '_'));
    }

    protected function fieldMatchesForbiddenPattern(string $field, array $forbiddenFields): bool
    {
        $normalizedField = $this->canonicalizeFieldName($field);

        foreach ($forbiddenFields as $pattern) {
            $normalizedPattern = $this->canonicalizeFieldName((string) $pattern);

            if ($normalizedPattern === '') {
                continue;
            }

            if (str_ends_with($normalizedPattern, '*')) {
                $prefix = substr($normalizedPattern, 0, -1);

                if ($prefix !== '' && str_starts_with($normalizedField, $prefix)) {
                    return true;
                }

                continue;
            }

            if ($normalizedField === $normalizedPattern) {
                return true;
            }
        }

        return false;
    }

    protected function ok(array $data = [], int $status = 200)
    {
        return response()->json(['success' => true] + $data, $status);
    }

    protected function writeAudit(
        Request $request,
        string $action,
        string $module,
        ?string $description = null,
        array $context = []
    ): void
    {
        $this->writeAuditEntry(
            $request->user()?->id,
            $action,
            $module,
            $description,
            $context,
            $request->ip(),
            $request->userAgent(),
        );
    }

    protected function writeAuditEntry(
        ?int $userId,
        string $action,
        string $module,
        ?string $description = null,
        array $context = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void
    {
        $before = $context['before'] ?? $context['old_values'] ?? null;
        $after = $context['after'] ?? $context['new_values'] ?? null;
        $reason = $context['reason'] ?? data_get($after, 'reason') ?? data_get($before, 'reason');
        $result = $context['result'] ?? data_get($after, 'result') ?? 'success';
        $entity = $context['entity'] ?? $module;
        $entityId = $context['entity_id']
            ?? data_get($after, 'id')
            ?? data_get($after, 'entity_id')
            ?? data_get($after, 'document_id')
            ?? data_get($after, 'provider_id')
            ?? data_get($after, 'aircraft_id')
            ?? data_get($after, 'reservation_id')
            ?? data_get($after, 'flight_request_id')
            ?? data_get($after, 'payment_id')
            ?? data_get($after, 'subscription_id')
            ?? data_get($before, 'id')
            ?? data_get($before, 'entity_id');
        $metadata = $context['metadata'] ?? array_diff_key(
            $context,
            array_flip(['before', 'after', 'old_values', 'new_values', 'reason', 'result', 'entity', 'entity_id', 'metadata'])
        );
        $actor = $userId ? Usuario::query()->find($userId) : null;

        app(AdminAuditServicio::class)->record(
            actor: $actor,
            action: $action,
            module: $module,
            entity: (string) $entity,
            entityId: $entityId,
            before: is_array($before) ? $before : null,
            after: is_array($after) ? $after : null,
            reason: is_string($reason) ? $reason : null,
            result: is_string($result) ? $result : 'success',
            metadata: is_array($metadata) ? $metadata : [],
            description: $description,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            requestId: request()?->headers->get('X-Request-Id') ?: request()?->attributes->get('request_id'),
        );
    }

    protected function resolvedProviderIdOrAbort(Request $request, int $status = 422): int
    {
        $providerId = (int) ($request->user()?->resolvedProviderId() ?? 0);
        abort_if($providerId <= 0, $status, 'El usuario proveedor no tiene un proveedor autorizado asignado.');

        return $providerId;
    }

    protected function rejectForbiddenPayloadFields(Request $request, array $forbiddenFields, string $message = 'El payload contiene campos no permitidos.'): void
    {
        $providedFields = collect($request->all())
            ->keys()
            ->map(fn ($field) => $this->canonicalizeFieldName((string) $field))
            ->filter(fn ($field) => $this->fieldMatchesForbiddenPattern($field, $forbiddenFields))
            ->unique()
            ->values();

        if ($providedFields->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'payload' => [
                $message.' Campos prohibidos: '.$providedFields->implode(', ').'.',
            ],
        ]);
    }
}
