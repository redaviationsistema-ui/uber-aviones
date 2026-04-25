<?php

namespace App\Enumeraciones;

enum EstadoSuscripcion: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case PastDue = 'past_due';
}
