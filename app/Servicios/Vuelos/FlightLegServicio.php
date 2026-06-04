<?php

namespace App\Servicios\Vuelos;

use App\Modelos\SolicitudVuelo;

class FlightLegServicio
{
    public function createInitialLeg(SolicitudVuelo $request)
    {
        return $request->legs()->create([
            'leg_order' => 1,
            'origin' => $request->origin,
            'origin_airport_id' => $request->origin_airport_id,
            'destination' => $request->destination,
            'destination_airport_id' => $request->destination_airport_id,
            'departure_datetime' => $request->departure_date->format('Y-m-d').' '.$request->departure_time,
        ]);
    }
}
