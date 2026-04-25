<?php

namespace App\Servicios\Pagos;

class PagoGatewayServicio
{
    public function charge(array $payload): array
    {
        return ['status' => 'paid', 'reference' => $payload['reference'] ?? null];
    }
}
