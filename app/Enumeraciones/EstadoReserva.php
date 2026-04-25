<?php

namespace App\Enumeraciones;

enum EstadoReserva: string
{
    case PendingPago = 'pending_payment';
    case Paid = 'paid';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
