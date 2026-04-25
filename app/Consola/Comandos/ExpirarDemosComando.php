<?php

namespace App\Consola\Comandos;

use App\Modelos\Demo;
use Illuminate\Console\Command;

class ExpirarDemosComando extends Command
{
    protected $signature = 'skygroup:expire-demos';

    protected $description = 'Marca como vencidas las demos expiradas.';

    public function handle(): int
    {
        $count = Demo::where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired']);
        $this->info("Demos expiradas: {$count}");

        return self::SUCCESS;
    }
}
