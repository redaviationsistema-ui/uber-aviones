<?php

namespace App\Servicios\Cotizaciones;

use App\Modelos\Cotizacion;

class CotizacionServicio
{
    public function markSent(Cotizacion $quote): Cotizacion
    {
        $quote->update(['status' => 'sent']);

        return $quote;
    }
}
