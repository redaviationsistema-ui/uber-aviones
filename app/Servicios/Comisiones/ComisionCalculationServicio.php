<?php

namespace App\Servicios\Comisiones;

class ComisionCalculationServicio
{
    public function split(float $amount, float $rate = 0.10): array
    {
        $platformFee = round($amount * $rate, 2);

        return ['platform_fee' => $platformFee, 'provider_amount' => round($amount - $platformFee, 2)];
    }
}
