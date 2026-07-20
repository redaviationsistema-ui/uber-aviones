<?php

namespace App\Servicios\Sobrecargo;

use App\Modelos\Operacion;
use App\Modelos\Usuario;
use App\Servicios\Administracion\AdminAuditServicio;
use Illuminate\Http\Request;

class CrewOperationalAuditService
{
    public function __construct(private readonly AdminAuditServicio $audit) {}

    public function record(
        Request $request,
        Usuario $actor,
        Operacion $operation,
        string $event,
        ?string $previousStatus,
        ?string $newStatus,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $this->audit->record(
            actor: $actor,
            action: $event,
            module: 'crew_operations',
            entity: 'operations',
            entityId: $operation->id,
            before: ['status' => $previousStatus],
            after: ['status' => $newStatus],
            reason: $reason,
            metadata: array_merge([
                'operation_id' => $operation->id,
                'assignment_id' => $metadata['assignment_id'] ?? null,
                'actor_role' => $actor->effectiveRole(),
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
            ], $metadata),
            description: 'Transicion operativa de sobrecargo.',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id'),
        );
    }
}
