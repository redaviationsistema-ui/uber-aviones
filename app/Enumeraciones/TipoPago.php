<?php

namespace App\Enumeraciones;

enum TipoPago: string
{
    case Suscripcion = 'subscription';
    case Reserva = 'reservation';
}
