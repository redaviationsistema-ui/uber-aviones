<?php

namespace App\Servicios\Reservas;

use App\Modelos\Cotizacion;
use App\Modelos\Reserva;

class ReservaServicio
{
    public function createFromCotizacion(Cotizacion $quote, string $code): Reserva
    {
        return Reserva::create([
            'client_id' => $quote->flightRequest->client_id,
            'provider_id' => $quote->provider_id,
            'aircraft_id' => $quote->aircraft_id,
            'flight_request_id' => $quote->flight_request_id,
            'quote_id' => $quote->id,
            'reservation_code' => $code,
            'status' => 'pending_payment',
            'total_amount' => $quote->total,
        ]);
    }
}
