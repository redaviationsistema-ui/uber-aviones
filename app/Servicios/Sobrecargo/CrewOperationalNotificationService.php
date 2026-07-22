<?php

namespace App\Servicios\Sobrecargo;

use App\Modelos\Notificacion;
use App\Modelos\Operacion;
use App\Modelos\Usuario;
use Illuminate\Support\Facades\Schema;

class CrewOperationalNotificationService
{
    private ?bool $supportsNotificationIdempotencyKey = null;

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

        $payload = array_merge([
            'level' => $level,
            'operation_id' => $operation->id,
            'assignment_id' => $assignmentId,
            'url' => "/sobrecargo/asignaciones/{$operation->id}",
        ], $extra);
        $data = array_merge([
            'level' => $level,
            'operation_id' => $operation->id,
            'assignment_id' => $assignmentId,
        ], $extra);

        if (! $this->notificationsSupportIdempotencyKey()) {
            $existing = Notificacion::query()
                ->where('user_id', $recipient->id)
                ->where('type', $type)
                ->where('title', $title)
                ->where('message', $message)
                ->latest('id')
                ->get()
                ->first(fn (Notificacion $notification) => $this->matchesFallbackNotification(
                    $notification,
                    $payload,
                    $data,
                ));

            if ($existing) {
                return $existing;
            }

            return Notificacion::query()->create([
                'user_id' => $recipient->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'payload' => $payload,
                'data' => $data,
            ]);
        }

        return Notificacion::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'user_id' => $recipient->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'payload' => $payload,
                'data' => $data,
            ],
        );
    }

    private function notificationsSupportIdempotencyKey(): bool
    {
        if ($this->supportsNotificationIdempotencyKey !== null) {
            return $this->supportsNotificationIdempotencyKey;
        }

        return $this->supportsNotificationIdempotencyKey = Schema::hasColumn('notifications', 'idempotency_key');
    }

    private function matchesFallbackNotification(Notificacion $notification, array $expectedPayload, array $expectedData): bool
    {
        $storedPayload = is_array($notification->payload) ? $notification->payload : [];
        $storedData = is_array($notification->data) ? $notification->data : [];

        foreach ($expectedPayload as $key => $value) {
            if (data_get($storedPayload, $key) !== $value) {
                return false;
            }
        }

        foreach ($expectedData as $key => $value) {
            if (data_get($storedData, $key) !== $value) {
                return false;
            }
        }

        return true;
    }
}
