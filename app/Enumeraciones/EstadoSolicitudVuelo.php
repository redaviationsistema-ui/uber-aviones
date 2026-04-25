<?php

namespace App\Enumeraciones;

enum EstadoSolicitudVuelo: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case Cotizaciond = 'quoted';
    case Reserved = 'reserved';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
