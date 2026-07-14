<?php

namespace Tests\Feature;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\Cotizacion;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientReservationCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_cancel_own_paid_reservation_and_audit_is_written(): void
    {
        [$client, $token, $flightRequest, $reservation, $payment] = $this->createReservationContext();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/cliente/reservas/'.$reservation->id.'/cancel', [
                'reason' => 'Cambio de itinerario del cliente.',
            ])
            ->assertOk()
            ->assertJsonPath('reservation.status', 'cancelled')
            ->assertJsonPath('flight_request.status', 'cancelled');

        $reservation->refresh();
        $flightRequest->refresh();
        $payment->refresh();
        $block = AircraftAvailabilityBlock::query()->where('reservation_id', $reservation->id)->latest('id')->firstOrFail();

        $this->assertSame('cancelled', $reservation->status);
        $this->assertSame('Cambio de itinerario del cliente.', $reservation->cancellation_reason);
        $this->assertSame('cancelled', $flightRequest->status);
        $this->assertSame('paid', $payment->status);
        $this->assertSame('cancelled', $block->status);
        $this->assertNotNull($block->released_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $client->id,
            'action' => 'reservation_cancelled',
            'module' => 'operations_history',
        ]);
    }

    public function test_client_cannot_cancel_foreign_reservation(): void
    {
        [, , , $reservation] = $this->createReservationContext();
        $otherClient = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => 'other.cancel.client@test.dev',
        ]);
        $otherToken = TokenApi::issue($otherClient, 'other-client-token');

        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->postJson('/api/v1/cliente/reservas/'.$reservation->id.'/cancel', [
                'reason' => 'Intento ajeno.',
            ])
            ->assertStatus(403);
    }

    public function test_client_cancellation_is_idempotent(): void
    {
        [$client, $token, , $reservation] = $this->createReservationContext();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/cliente/reservas/'.$reservation->id.'/cancel', [
                'reason' => 'Primera cancelacion.',
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/cliente/reservas/'.$reservation->id.'/cancel', [
                'reason' => 'Segunda cancelacion.',
            ])
            ->assertOk()
            ->assertJsonPath('reservation.status', 'cancelled');

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $client->id,
            'action' => 'reservation_cancelled',
            'module' => 'operations_history',
        ]);
    }

    public function test_client_cannot_cancel_after_flight_started(): void
    {
        [, $token, $flightRequest, $reservation] = $this->createReservationContext(departureOffsetDays: -1);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/cliente/reservas/'.$reservation->id.'/cancel', [
                'reason' => 'Demasiado tarde.',
            ])
            ->assertStatus(409);

        $reservation->refresh();
        $flightRequest->refresh();

        $this->assertSame('confirmed', $reservation->status);
        $this->assertSame('reserved', $flightRequest->status);
    }

    public function test_guest_cannot_cancel_reservation(): void
    {
        [, , , $reservation] = $this->createReservationContext();

        $this->postJson('/api/v1/cliente/reservas/'.$reservation->id.'/cancel', [
            'reason' => 'Sin sesion.',
        ])->assertStatus(401);
    }

    /**
     * @return array{0: Usuario, 1: string, 2: \App\Modelos\SolicitudVuelo, 3: Reserva, 4: Pago}
     */
    private function createReservationContext(int $departureOffsetDays = 4): array
    {
        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => 'client.cancel@test.dev',
        ]);

        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => 'provider.cancel@test.dev',
        ]);

        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Provider Cancel',
            'commercial_name' => 'Provider Cancel',
            'approval_status' => 'approved',
        ]);

        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation Cancel',
            'category' => 'Light Jet',
            'registration' => 'XA-CAN1',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2400,
            'speed_kmh' => 690,
            'hourly_rate' => 5000,
            'climb_descent_minutes' => 30,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        $departure = now()->addDays($departureOffsetDays)->setTime(10, 0);
        $arrival = $departure->copy()->addHours(2);

        $flightRequest = \App\Modelos\SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => $departure,
            'return_datetime' => $arrival,
            'departure_date' => $departure->toDateString(),
            'departure_time' => $departure->format('H:i'),
            'passengers' => 3,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'final_price' => 15990,
            'currency' => 'USD',
            'status' => 'reserved',
            'workflow_status' => 'vuelo confirmado',
            'payment_status' => 'paid',
            'pricing_context' => [
                'flight_cost' => 15000,
                'base_amount' => 15000,
                'total_amount' => 15990,
                'selected_card_price' => 15990,
                'total' => 15990,
                'final_price' => 15990,
            ],
        ]);

        $quote = Cotizacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'subtotal' => 15000,
            'taxes' => 0,
            'fees' => 990,
            'total' => 15990,
            'currency' => 'USD',
            'status' => 'accepted',
            'expires_at' => now()->addDays(2),
        ]);

        $reservation = Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'quote_id' => $quote->id,
            'reservation_code' => 'PV-CANCEL-001',
            'status' => 'confirmed',
            'total_amount' => 15990,
            'currency' => 'USD',
            'confirmed_at' => now(),
        ]);

        $payment = Pago::query()->create([
            'user_id' => $client->id,
            'reservation_id' => $reservation->id,
            'flight_request_id' => $flightRequest->id,
            'payment_type' => 'reservation',
            'amount' => 15990,
            'currency' => 'USD',
            'provider' => 'stripe',
            'transaction_reference' => 'pi_cancel_001',
            'stripe_payment_intent_id' => 'pi_cancel_001',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $aircraft->id,
            'reservation_id' => $reservation->id,
            'block_type' => 'reservation',
            'start_datetime' => $departure,
            'end_datetime' => $arrival,
            'status' => 'booked',
            'reason' => 'Reserva pagada',
        ]);

        $token = TokenApi::issue($client, 'client-cancel-token');

        return [$client, $token, $flightRequest, $reservation, $payment];
    }
}
