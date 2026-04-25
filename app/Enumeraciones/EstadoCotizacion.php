<?php

namespace App\Enumeraciones;

enum EstadoCotizacion: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
