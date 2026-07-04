<?php

namespace Tests\Feature;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\Cotizacion;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AvailabilityBlockAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_stripe_refund_webhook_releases_paid_reservation_block(): void
    {
        [$flightRequest, $reservation, , , , $payment] = $this->createPaidReservationContext();
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $event = (object) [
            'id' => 'evt_refund_001',
            'type' => 'charge.refunded',
            'created' => Carbon::parse('2026-07-04 12:00:00', 'UTC')->timestamp,
            'data' => (object) [
                'object' => (object) [
                    'id' => 'ch_refund_001',
                    'payment_intent' => 'pi_paid_001',
                    'metadata' => (object) [
                        'flight_request_id' => (string) $flightRequest->id,
                    ],
                ],
            ],
        ];

        $webhookAlias = Mockery::mock('alias:Stripe\Webhook');
        $webhookAlias
            ->shouldReceive('constructEvent')
            ->once()
            ->andReturn($event);

        $this->postJson('/api/v1/stripe/webhook', [], [
            'Stripe-Signature' => 't=1,v1=fake',
        ])->assertOk();

        $flightRequest->refresh();
        $reservation->refresh();
        $payment->refresh();
        $block = AircraftAvailabilityBlock::query()->where('reservation_id', $reservation->id)->latest('id')->firstOrFail();

        $this->assertSame('refunded', $payment->status);
        $this->assertSame('refunded', $flightRequest->payment_status);
        $this->assertSame('pending_payment', $reservation->status);
        $this->assertSame('cancelled', $block->status);
        $this->assertNotNull($block->released_at);
    }

    public function test_admin_assign_reblocks_paid_reservation_when_aircraft_changes(): void
    {
        $this->seed();
        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        [$flightRequest, $reservation, $provider, $aircraftA] = $this->createPaidReservationContext();
        $aircraftB = $this->createAircraft($provider, 'XA-AUD2', 'Learjet 75');

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/requests/{$flightRequest->id}/assign", [
                'provider_id' => $provider->id,
                'aircraft_id' => $aircraftB->id,
            ])
            ->assertOk();

        $reservation->refresh();
        $flightRequest->refresh();
        $activeBlock = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'active')
            ->latest('id')
            ->firstOrFail();
        $releasedOldBlock = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('aircraft_id', $aircraftA->id)
            ->where('status', 'released')
            ->exists();

        $this->assertSame($aircraftB->id, $flightRequest->assigned_aircraft_id);
        $this->assertSame($aircraftB->id, $reservation->aircraft_id);
        $this->assertSame($aircraftB->id, $activeBlock->aircraft_id);
        $this->assertTrue($releasedOldBlock);
    }

    public function test_provider_release_reblocks_paid_reservation_when_operational_aircraft_changes(): void
    {
        [$flightRequest, $reservation, $provider, $aircraftA, $providerUser] = $this->createPaidReservationContext();
        $aircraftB = $this->createAircraft($provider, 'XA-AUD3', 'Hawker 900XP');
        $providerToken = TokenApi::issue($providerUser->fresh(), 'provider-audit-token');

        $this->withHeader('Authorization', 'Bearer '.$providerToken)
            ->putJson("/api/v1/proveedor/solicitudes/{$flightRequest->id}/release-provider", [
                'provider_operational_release' => [
                    'status' => 'aircraft_confirmed',
                    'aircraft_id' => $aircraftB->id,
                    'aircraft_label' => $aircraftB->model,
                    'availability_confirmed' => true,
                ],
                'operational_status' => 'aircraft_confirmed',
                'workflow_status' => 'flight_confirmed',
                'aircraft_confirmed' => true,
            ])
            ->assertOk();

        $reservation->refresh();
        $flightRequest->refresh();
        $activeBlock = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'active')
            ->latest('id')
            ->firstOrFail();
        $releasedOldBlock = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('aircraft_id', $aircraftA->id)
            ->where('status', 'released')
            ->exists();

        $this->assertSame($aircraftB->id, $flightRequest->assigned_aircraft_id);
        $this->assertSame($aircraftB->id, $reservation->aircraft_id);
        $this->assertSame($aircraftB->id, $activeBlock->aircraft_id);
        $this->assertTrue($releasedOldBlock);
    }

    /**
     * @return array{0: SolicitudVuelo, 1: Reserva, 2: Proveedor, 3: Aeronave, 4: Usuario, 5: Pago}
     */
    private function createPaidReservationContext(): array
    {
        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => 'client.audit@test.dev',
        ]);

        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => 'provider.audit@test.dev',
        ]);

        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Provider Audit',
            'commercial_name' => 'Provider Audit',
            'approval_status' => 'approved',
        ]);

        $providerUser->forceFill([
            'provider_id' => $provider->id,
        ])->save();

        $aircraft = $this->createAircraft($provider, 'XA-AUD1', 'Citation Sovereign');

        $departure = now()->addDays(5)->setTime(9, 0, 0);
        $arrival = now()->addDays(5)->setTime(12, 30, 0);

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => $departure,
            'return_datetime' => $arrival,
            'departure_date' => $departure->toDateString(),
            'departure_time' => $departure->format('H:i'),
            'passengers' => 4,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
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
            'reservation_code' => 'PV-AUDIT-001',
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
            'transaction_reference' => 'pi_paid_001',
            'stripe_payment_intent_id' => 'pi_paid_001',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $aircraft->id,
            'reservation_id' => $reservation->id,
            'block_type' => 'reservation',
            'start_datetime' => $departure,
            'end_datetime' => $arrival,
            'status' => 'active',
            'reason' => 'Reserva pagada',
        ]);

        return [$flightRequest, $reservation, $provider, $aircraft, $providerUser, $payment];
    }

    private function createAircraft(Proveedor $provider, string $registration, string $model): Aeronave
    {
        return Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => $model,
            'registration' => $registration,
            'category' => 'Light Jet',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 5000,
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }
}
