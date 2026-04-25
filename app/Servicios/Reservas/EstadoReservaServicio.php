<?php

namespace App\Servicios\Reservas;

use App\Modelos\Reserva;

class EstadoReservaServicio
{
    public function confirm(Reserva $reservation): Reserva
    {
        $reservation->update(['status' => 'confirmed']);

        return $reservation;
    }
}
