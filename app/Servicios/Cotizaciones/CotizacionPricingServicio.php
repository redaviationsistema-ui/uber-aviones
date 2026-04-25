<?php

namespace App\Servicios\Cotizaciones;

class CotizacionPricingServicio
{
    public function total(float $subtotal, float $taxes = 0, float $fees = 0): float
    {
        return round($subtotal + $taxes + $fees, 2);
    }
}
