<?php

namespace App\Consola\Comandos;

use App\Servicios\Billing\ProviderAircraftSubscriptionService;
use Illuminate\Console\Command;

class ExpirarSuscripcionesAeronavesComando extends Command
{
    protected $signature = 'skygroup:expire-aircraft-subscriptions';

    protected $description = 'Marca como vencidas las suscripciones por aeronave expiradas y desactiva la aeronave.';

    public function __construct(private readonly ProviderAircraftSubscriptionService $providerAircraftSubscriptionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->providerAircraftSubscriptionService->expireLapsedSubscriptions();
        $this->info("Suscripciones por aeronave expiradas: {$count}");

        return self::SUCCESS;
    }
}
