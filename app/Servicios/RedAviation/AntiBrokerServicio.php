<?php

namespace App\Servicios\RedAviation;

use App\Modelos\BanderaAntiBroker;
use App\Modelos\MensajeChat;
use App\Modelos\Notificacion;
use App\Modelos\RegistroAuditoria;
use App\Modelos\Usuario;

class AntiBrokerServicio
{
    public function inspeccionar(?string $mensaje): array
    {
        $mensaje = trim((string) $mensaje);
        $patrones = [
            'telefono' => '/(\+?\d[\d\-\s]{7,}\d)/',
            'correo' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            'url' => '/https?:\/\/[^\s]+|www\.[^\s]+/i',
            'whatsapp' => '/\bwhats?app\b|\bwa\.me\b/i',
            'red_social' => '/\b(instagram|facebook|telegram|linkedin|tiktok|x\.com|twitter)\b/i',
        ];

        $hallazgos = [];
        $sanitizado = $mensaje;

        foreach ($patrones as $tipo => $regex) {
            if (preg_match_all($regex, $mensaje, $coincidencias)) {
                foreach ($coincidencias[0] as $valor) {
                    $hallazgos[] = ['type' => $tipo, 'value' => $valor];
                    $sanitizado = str_replace($valor, '[contenido protegido por Red Aviation]', $sanitizado);
                }
            }
        }

        return [
            'original' => $mensaje,
            'sanitized' => $sanitizado,
            'has_blocked_content' => $hallazgos !== [],
            'flags' => $hallazgos,
        ];
    }

    public function registrarIncidencias(Usuario $usuario, ?int $flightRequestId, MensajeChat $mensaje, array $revision): void
    {
        if (! $revision['has_blocked_content']) {
            return;
        }

        foreach ($revision['flags'] as $flag) {
            BanderaAntiBroker::create([
                'user_id' => $usuario->id,
                'flight_request_id' => $flightRequestId,
                'message_id' => $mensaje->id,
                'type' => $flag['type'],
                'detected_value' => $flag['value'],
                'severity' => 'alta',
                'status' => 'abierta',
            ]);
        }

        $usuario->increment('contact_strikes');

        RegistroAuditoria::create([
            'user_id' => $usuario->id,
            'action' => 'anti_broker.detectado',
            'module' => 'chat',
            'description' => 'Se detecto intento de contacto externo.',
            'old_values' => ['message_original' => $revision['original']],
            'new_values' => ['message_sanitized' => $revision['sanitized'], 'flags' => $revision['flags']],
        ]);

        $admin = Usuario::query()
            ->where(function ($query) {
                $query->where('role', Usuario::ROLE_ADMIN)
                    ->orWhereHas('roles', fn ($roles) => $roles->where('code', Usuario::ROLE_ADMIN));
            })
            ->first();
        if ($admin) {
            Notificacion::create([
                'user_id' => $admin->id,
                'type' => 'anti_broker',
                'title' => 'Alerta anti-broker',
                'message' => 'Se detecto un intento de compartir contacto externo.',
                'data' => [
                    'flagged_user_id' => $usuario->id,
                    'flight_request_id' => $flightRequestId,
                    'message_id' => $mensaje->id,
                ],
            ]);
        }
    }
}
