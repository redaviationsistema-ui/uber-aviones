<?php

namespace App\Servicios\RedAviation;

use App\Modelos\SolicitudVuelo;

class ProviderFlightRequestNotificationService
{
    public function __construct(private readonly ProviderFlightNotificationService $notifications) {}

    public function dispatchForFlightRequest(SolicitudVuelo|int $flightRequest): void
    {
        $this->notifications->notifyRequestCreated($flightRequest);
    }
}
