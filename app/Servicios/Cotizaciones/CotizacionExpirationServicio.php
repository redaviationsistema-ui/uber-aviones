<?php

namespace App\Servicios\Cotizaciones;

use App\Modelos\Cotizacion;

class CotizacionExpirationServicio
{
    public function expireOld(): int
    {
        return Cotizacion::whereIn('status', ['pending', 'sent'])->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }
}
