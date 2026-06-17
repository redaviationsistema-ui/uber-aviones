<?php

namespace App\Consola\Comandos;

use App\Modelos\Notificacion;
use App\Modelos\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpirarAccesosClientesComando extends Command
{
    protected $signature = 'skygroup:expire-client-access';

    protected $description = 'Sincroniza vencimiento, gracia y suspension del acceso comercial de clientes.';

    public function handle(): int
    {
        $suspended = 0;
        $expired = 0;

        Usuario::query()
            ->where('access_status', 'past_due')
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<=', now())
            ->chunkById(100, function ($users) use (&$suspended) {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'access_status' => 'suspended',
                        'has_paid_access' => false,
                        'next_retry_at' => null,
                        'updated_at' => now(),
                    ]);

                    Notificacion::create([
                        'user_id' => $user->id,
                        'type' => 'access_suspended',
                        'title' => 'Acceso comercial suspendido',
                        'message' => 'El periodo de gracia termino y tu acceso comercial quedo suspendido hasta actualizar el metodo de pago.',
                        'data' => [
                            'source' => 'skygroup:expire-client-access',
                            'grace_period_ended_at' => optional($user->grace_period_ends_at)->toIso8601String(),
                        ],
                    ]);

                    $suspended++;
                }
            });

        Usuario::query()
            ->where('has_paid_access', true)
            ->whereIn('access_status', ['active', 'payment_pending'])
            ->whereNotNull('access_expires_at')
            ->where('access_expires_at', '<=', now())
            ->chunkById(100, function ($users) use (&$expired) {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'has_paid_access' => false,
                        'access_status' => 'expired',
                        'grace_period_ends_at' => null,
                        'next_retry_at' => null,
                        'updated_at' => now(),
                    ]);

                    $expired++;
                }
            });

        $this->info("Accesos suspendidos por gracia vencida: {$suspended}");
        $this->info("Accesos comerciales expirados: {$expired}");

        return self::SUCCESS;
    }
}
