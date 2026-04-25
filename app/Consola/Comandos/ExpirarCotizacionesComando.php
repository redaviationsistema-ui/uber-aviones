<?php

namespace App\Consola\Comandos;

use App\Modelos\Cotizacion;
use Illuminate\Console\Command;

class ExpirarCotizacionesComando extends Command
{
    protected $signature = 'skygroup:expire-quotes';

    protected $description = 'Marca como vencidas las cotizaciones expiradas.';

    public function handle(): int
    {
        $count = Cotizacion::whereIn('status', ['pending', 'sent'])->where('expires_at', '<=', now())->update(['status' => 'expired']);
        $this->info("Cotizaciones expiradas: {$count}");

        return self::SUCCESS;
    }
}
