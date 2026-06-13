<?php

namespace App\Consola\Comandos;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpirarAccesosClientesComando extends Command
{
    protected $signature = 'skygroup:expire-client-access';

    protected $description = 'Desactiva accesos comerciales de clientes cuya vigencia mensual ya expiro.';

    public function handle(): int
    {
        $count = DB::table('users')
            ->where('has_paid_access', true)
            ->whereNotNull('access_expires_at')
            ->where('access_expires_at', '<=', now())
            ->update([
                'has_paid_access' => false,
                'access_status' => 'trial_used',
                'updated_at' => now(),
            ]);

        $this->info("Accesos comerciales expirados: {$count}");

        return self::SUCCESS;
    }
}
