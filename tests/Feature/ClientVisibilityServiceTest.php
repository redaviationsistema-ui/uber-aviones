<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Cotizacion;
use App\Modelos\Proveedor;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use App\Servicios\RedAviation\VisibilidadServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ClientVisibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_visible_quote_total_prefers_current_pricing_context_over_stale_accepted_quote(): void
    {
        $this->seed();
        Log::spy();
        $client = Usuario::query()->firstOrFail();
        $provider = Proveedor::query()->firstOrFail();

        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Visibility Audit Jet',
            'category' => 'Light Jet',
            'capacity' => 6,
            'base_airport' => 'MMTO',
            'range_km' => 2400,
            'speed_kmh' => 690,
            'hourly_rate' => 5000,
            'climb_descent_minutes' => 30,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => 'MMTO',
            'destination' => 'MMMX',
            'departure_datetime' => now()->addDays(4)->setTime(10, 0),
            'departure_date' => now()->addDays(4)->toDateString(),
            'departure_time' => '10:00',
            'passengers' => 3,
            'trip_type' => 'one_way',
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'aircraft_type' => 'Light Jet',
            'final_price' => 9931.57,
            'currency' => 'USD',
            'status' => 'quoted',
            'workflow_status' => 'cotizada',
            'pricing_context' => [
                'total_amount' => 9931.57,
                'total' => 9931.57,
                'final_price' => 9931.57,
            ],
            'visibility_payload' => [
                'selected_card_price' => 17692.56,
            ],
        ]);

        Cotizacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'aircraft_id' => $aircraft->id,
            'subtotal' => 15000,
            'taxes' => 0,
            'fees' => 2692.56,
            'total' => 17692.56,
            'currency' => 'USD',
            'status' => 'accepted',
            'expires_at' => now()->addDays(2),
        ]);

        $payload = app(VisibilidadServicio::class)->solicitudParaCliente(
            $flightRequest->fresh(['quotes', 'assignedAircraft.images']),
            [
                'include_timeline' => false,
                'include_matches' => false,
                'skip_reservation_lookup' => true,
            ],
        );

        $this->assertSame(9931.57, (float) $payload['quote_total']);
        $this->assertSame(9931.57, (float) $payload['total_amount']);
        $this->assertSame(17692.56, (float) data_get($payload, 'accepted_quote.total'));

        Log::shouldNotHaveReceived('warning', [
            'Client visible quote totals diverged',
            \Mockery::any(),
        ]);
    }
}
