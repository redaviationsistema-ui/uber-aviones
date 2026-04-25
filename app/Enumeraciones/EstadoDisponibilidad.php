<?php

namespace App\Enumeraciones;

enum EstadoDisponibilidad: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Blocked = 'blocked';
    case Maintenance = 'maintenance';
}
