<?php

namespace App\Consola\Comandos;

use App\Servicios\Aeronaves\AircraftAvailabilityService;
use Illuminate\Console\Command;

class ExpirarAircraftHoldsComando extends Command
{
    protected $signature = 'skygroup:expire-aircraft-holds';

    protected $description = 'Expira retenciones temporales de aeronaves cuyo tiempo de pago ya vencio.';

    public function __construct(private readonly AircraftAvailabilityService $aircraftAvailabilityService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->aircraftAvailabilityService->expireStaleHolds();
        $this->info("Aircraft holds expirados: {$count}");

        return self::SUCCESS;
    }
}
