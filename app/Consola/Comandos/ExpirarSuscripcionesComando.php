<?php

namespace App\Consola\Comandos;

use App\Modelos\Suscripcion;
use Illuminate\Console\Command;

class ExpirarSuscripcionesComando extends Command
{
    protected $signature = 'skygroup:expire-subscriptions';

    protected $description = 'Marca como vencidas las suscripciones expiradas.';

    public function handle(): int
    {
        $count = Suscripcion::where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired']);
        $this->info("Suscripciones expiradas: {$count}");

        return self::SUCCESS;
    }
}
