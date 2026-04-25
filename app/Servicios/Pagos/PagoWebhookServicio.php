<?php

namespace App\Servicios\Pagos;

class PagoWebhookServicio
{
    public function acknowledge(string $provider): array
    {
        return ['success' => true, 'provider' => $provider];
    }
}
