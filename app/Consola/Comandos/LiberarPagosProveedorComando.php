<?php

namespace App\Consola\Comandos;

use App\Modelos\Comision;
use App\Modelos\PagoProveedor;
use Illuminate\Console\Command;

class LiberarPagosProveedorComando extends Command
{
    protected $signature = 'skygroup:release-provider-payments';

    protected $description = 'Libera pagos retenidos a proveedores.';

    public function handle(): int
    {
        $released = 0;

        Comision::where('status', 'held')->with('provider')->chunkById(100, function ($commissions) use (&$released) {
            foreach ($commissions as $commission) {
                PagoProveedor::create([
                    'provider_id' => $commission->provider_id,
                    'commission_id' => $commission->id,
                    'amount' => $commission->provider_amount,
                    'currency' => 'USD',
                    'status' => 'released',
                    'released_at' => now(),
                ]);

                $commission->update(['status' => 'released']);
                $released++;
            }
        });

        $this->info("Pagos liberados: {$released}");

        return self::SUCCESS;
    }
}
