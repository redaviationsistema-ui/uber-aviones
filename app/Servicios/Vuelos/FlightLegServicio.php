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
            'destination' => $request->destination,
            'departure_datetime' => $request->departure_date->format('Y-m-d').' '.$request->departure_time,
        ]);
    }
}
