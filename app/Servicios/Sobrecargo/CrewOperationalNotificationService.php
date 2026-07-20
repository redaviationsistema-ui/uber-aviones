<?php

namespace App\Servicios\Sobrecargo;

use App\Modelos\Notificacion;
use App\Modelos\Operacion;
use App\Modelos\Usuario;

class CrewOperationalNotificationService
{
    public function send(
        Usuario $recipient,
        Operacion $operation,
        string $type,
        string $title,
        string $message,
        string $level = 'info',
        ?int $assignmentId = null,
        array $extra = [],
    ): Notificacion {
        $context = (string) ($extra['idempotency_context'] ?? '');
        unset($extra['idempotency_context']);
        $idempotencyKey = implode(':', array_filter([
            $type,
            'operation_'.$operation->id,
            'assignment_'.($assignmentId ?: 'none'),
            $context !== '' ? $context : null,
            'user_'.$recipient->id,
            'v1',
        ]));

        return Notificacion::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'user_id' => $recipient->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'payload' => array_merge([
                    'level' => $level,
                    'operation_id' => $operation->id,
                    'assignment_id' => $assignmentId,
                    'url' => "/sobrecargo/asignaciones/{$operation->id}",
                ], $extra),
                'data' => array_merge([
                    'level' => $level,
                    'operation_id' => $operation->id,
                    'assignment_id' => $assignmentId,
                ], $extra),
            ],
        );
    }
}
