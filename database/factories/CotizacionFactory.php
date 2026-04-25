<?php

namespace Database\Factories;

use App\Modelos\Aeronave;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Proveedor;
use App\Modelos\Cotizacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cotizacion> */
class CotizacionFactory extends Factory
{
    protected $model = Cotizacion::class;

    public function definition(): array
    {
        $provider = Proveedor::factory()->create();
        $aircraft = Aeronave::factory()->create(['provider_id' => $provider->id]);

        return [
            'flight_request_id' => SolicitudVuelo::factory(),
            'aircraft_id' => $aircraft->id,
            'provider_id' => $provider->id,
            'subtotal' => 10000,
            'taxes' => 1600,
            'fees' => 500,
            'total' => 12100,
            'currency' => 'USD',
            'status' => 'sent',
            'expires_at' => now()->addDays(2),
        ];
    }
}
