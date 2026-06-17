<?php

namespace App\Consola\Comandos;

use App\Modelos\Notificacion;
use App\Modelos\Usuario;
use Illuminate\Console\Command;

class EnviarRecordatoriosRenovacionAccesoClienteComando extends Command
{
    protected $signature = 'skygroup:send-access-renewal-reminders';

    protected $description = 'Genera recordatorios de renovacion para accesos comerciales de clientes.';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $sent = 0;

        Usuario::query()
            ->where('has_paid_access', true)
            ->where('access_status', 'active')
            ->whereNotNull('access_expires_at')
            ->chunkById(100, function ($users) use (&$sent, $today) {
                foreach ($users as $user) {
                    $expiry = optional($user->access_expires_at)?->copy()?->startOfDay();
                    if (! $expiry) {
                        continue;
                    }

                    $daysUntil = $today->diffInDays($expiry, false);
                    if (! in_array($daysUntil, [7, 3, 1], true)) {
                        continue;
                    }

                    $type = sprintf('access_renewal_%s_day_reminder', $daysUntil);
                    $alreadySent = Notificacion::query()
                        ->where('user_id', $user->id)
                        ->where('type', $type)
                        ->whereDate('created_at', now()->toDateString())
                        ->exists();

                    if ($alreadySent) {
                        continue;
                    }

                    $message = match ($daysUntil) {
                        7 => 'Tu suscripcion comercial vence en 7 dias. Revisa tu metodo de pago para asegurar la renovacion automatica.',
                        3 => 'Tu suscripcion comercial vence en 3 dias. Aun estas a tiempo de revisar tu metodo de pago y evitar fricciones.',
                        default => 'Tu suscripcion comercial vence manana. Revisa hoy tu metodo de pago para evitar entrar en gracia.',
                    };

                    Notificacion::create([
                        'user_id' => $user->id,
                        'type' => $type,
                        'title' => 'Recordatorio de renovacion de acceso comercial',
                        'message' => $message,
                        'data' => [
                            'days_until' => $daysUntil,
                            'access_expires_at' => $user->access_expires_at?->toIso8601String(),
                            'source' => 'skygroup:send-access-renewal-reminders',
                        ],
                    ]);

                    $sent++;
                }
            });

        $this->info("Recordatorios generados: {$sent}");

        return self::SUCCESS;
    }
}
