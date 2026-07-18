<?php

namespace App\Jobs;

use App\Servicios\RedAviation\ProviderFlightRequestNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchProviderFlightRequestNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $flightRequestId)
    {
    }

    public function handle(ProviderFlightRequestNotificationService $service): void
    {
        $service->dispatchForFlightRequest($this->flightRequestId);
    }
}
