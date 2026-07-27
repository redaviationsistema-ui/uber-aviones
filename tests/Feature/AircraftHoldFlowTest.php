<?php

namespace Tests\Feature;

use App\Http\Controladores\StripeWebhookControlador;
use App\Modelos\Aeronave;
use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Cotizacion;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use App\Servicios\Aeronaves\AircraftAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AircraftHoldFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_aircraft_without_blocks_appears_in_results(): void
    {
        $this->seed();

        $provider = $this->createProvider();
        $aircraft = $this->createAircraft($provider, 'XA-HOLD1');

        $response = $this->postJson('/api/v1/client/quotes/preview', $this->previewPayload());

        $response->assertOk();
        $ids = collect($response->json('matches'))->pluck('aircraft_id')->all();
        $this->assertContains($aircraft->id, $ids);
    }

    public function test_active_hold_excludes_aircraft_for_same_schedule(): void
    {
        $this->seed();

        [$client, $token, $quote, $aircraft] = $this->createAcceptedQuoteContext('XA-HOLD2');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated()
            ->assertJsonPath('data.status', 'held');

        $response = $this->postJson('/api/v1/client/quotes/preview', $this->previewPayload());
        $ids = collect($response->json('matches'))->pluck('aircraft_id')->all();

        $this->assertNotContains($aircraft->id, $ids);
    }

    public function test_repeated_hold_request_reuses_existing_hold_and_returns_200(): void
    {
        $this->seed();

        [, $token, $quote] = $this->createAcceptedQuoteContext('XA-HOLD2B');

        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated();

        $second = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertOk()
            ->assertJsonPath('data.status', 'held');

        $this->assertSame($first->json('data.hold_id'), $second->json('data.hold_id'));
        $this->assertSame(1, AircraftAvailabilityBlock::query()->where('quote_id', $quote->id)->where('status', 'held')->count());
    }

    public function test_hold_resolves_departure_from_request_payload_when_quote_window_is_missing(): void
    {
        $this->seed();

        [, $token, $quote] = $this->createAcceptedQuoteContext('XA-HOLD2C');
        $quote->flightRequest()->update([
            'departure_datetime' => null,
            'return_datetime' => null,
            'departure_date' => null,
            'departure_time' => null,
            'return_date' => null,
            'return_time' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold", [
                'quote_id' => $quote->id,
                'aircraft_id' => $quote->aircraft_id,
                'departure_date' => '2026-08-20',
                'departure_time' => '10:00',
                'departure_datetime' => '2026-08-20T10:00:00',
                'start_date' => '2026-08-20',
                'start_time' => '10:00',
                'start_datetime' => '2026-08-20T10:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.quote_id', $quote->id);

        $this->assertDatabaseHas('aircraft_availability_blocks', [
            'id' => $response->json('data.hold_id'),
            'quote_id' => $quote->id,
            'aircraft_id' => $quote->aircraft_id,
            'status' => 'held',
        ]);
    }

    public function test_hold_resolves_departure_from_flight_request_date_and_time_when_datetime_is_missing(): void
    {
        $this->seed();

        [, $token, $quote] = $this->createAcceptedQuoteContext('XA-HOLD2E');
        $quote->flightRequest()->update([
            'departure_datetime' => null,
            'return_datetime' => null,
            'departure_date' => '2026-08-20',
            'departure_time' => '10:00',
            'return_date' => '2026-08-20',
            'return_time' => '14:00',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated()
            ->assertJsonPath('data.quote_id', $quote->id);

        $this->assertDatabaseHas('aircraft_availability_blocks', [
            'id' => $response->json('data.hold_id'),
            'quote_id' => $quote->id,
            'aircraft_id' => $quote->aircraft_id,
            'status' => 'held',
        ]);
    }

    public function test_hold_rejects_when_body_quote_id_does_not_match_route_quote(): void
    {
        $this->seed();

        [, $token, $quote] = $this->createAcceptedQuoteContext('XA-HOLD2D');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold", [
                'quote_id' => $quote->id + 999,
                'aircraft_id' => $quote->aircraft_id,
                'departure_datetime' => '2026-08-20T10:00:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El quote_id enviado no coincide con la cotización de la URL.');
    }

    public function test_expired_hold_does_not_exclude_aircraft_and_command_is_idempotent(): void
    {
        $this->seed();

        [, $token, $quote, $aircraft] = $this->createAcceptedQuoteContext('XA-HOLD3');
        $window = $this->makeWindow(startDays: 7, startHour: 15, durationHours: 99);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold", $window['payload'])
            ->assertCreated();

        AircraftAvailabilityBlock::query()->where('quote_id', $quote->id)->update([
            'hold_expires_at' => now()->subMinute(),
        ]);

        $this->artisan('skygroup:expire-aircraft-holds')->assertExitCode(0);
        $this->artisan('skygroup:expire-aircraft-holds')->assertExitCode(0);

        $response = $this->postJson('/api/v1/client/quotes/preview', $this->previewPayload());
        $ids = collect($response->json('matches'))->pluck('aircraft_id')->all();

        $this->assertContains($aircraft->id, $ids);
        $this->assertDatabaseHas('aircraft_availability_blocks', [
            'quote_id' => $quote->id,
            'status' => 'expired',
        ]);
    }

    public function test_same_client_can_retry_after_own_hold_expires_and_receives_a_new_hold(): void
    {
        $this->seed();

        [, $token, $quote] = $this->createAcceptedQuoteContext('XA-HOLD3B');

        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated();

        AircraftAvailabilityBlock::query()->whereKey($first->json('data.hold_id'))->update([
            'hold_expires_at' => now()->subMinute(),
        ]);

        $retry = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated()
            ->assertJsonPath('data.status', 'held');

        $this->assertNotSame($first->json('data.hold_id'), $retry->json('data.hold_id'));
        $this->assertDatabaseHas('aircraft_availability_blocks', [
            'id' => $first->json('data.hold_id'),
            'status' => 'expired',
        ]);
        $this->assertSame(1, AircraftAvailabilityBlock::query()->where('quote_id', $quote->id)->where('status', 'held')->count());
    }

    public function test_invoice_paid_converts_hold_into_booked_without_duplicate_block(): void
    {
        $this->seed();

        [$client, $token, $quote, $aircraft, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD4');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated();

        $reservation = Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $quote->provider_id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'quote_id' => $quote->id,
            'reservation_code' => 'PV-HOLD-001',
            'status' => 'pending_payment',
            'total_amount' => $quote->total,
            'currency' => 'USD',
        ]);

        AircraftAvailabilityBlock::query()->where('quote_id', $quote->id)->update([
            'reservation_id' => $reservation->id,
        ]);

        Pago::query()->create([
            'user_id' => $client->id,
            'reservation_id' => $reservation->id,
            'flight_request_id' => $flightRequest->id,
            'payment_type' => 'reservation',
            'amount' => $quote->total,
            'currency' => 'USD',
            'provider' => 'stripe',
            'transaction_reference' => 'cs_hold_paid',
            'stripe_checkout_session_id' => 'cs_hold_paid',
            'status' => 'pending',
        ]);

        $session = (object) [
            'id' => 'cs_hold_paid',
            'payment_intent' => 'pi_hold_paid',
            'amount_total' => 1210000,
            'currency' => 'usd',
            'metadata' => (object) [
                'flight_request_id' => (string) $flightRequest->id,
            ],
        ];

        $this->invokePrivateWebhookMethod('handleCheckoutCompleted', $session);
        $this->invokePrivateWebhookMethod('handleCheckoutCompleted', $session);

        $this->assertDatabaseHas('aircraft_availability_blocks', [
            'reservation_id' => $reservation->id,
            'status' => 'booked',
        ]);
        $this->assertSame(1, AircraftAvailabilityBlock::query()->where('reservation_id', $reservation->id)->where('status', 'booked')->count());
    }

    public function test_paid_reservation_booked_block_reuses_hold_window_when_request_legs_are_stale(): void
    {
        $this->seed();

        [$client, $token, $quote, $aircraft, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD4B');
        $holdWindow = $this->makeWindow(startDays: 14, startHour: 15, durationHours: 99);
        $staleLegWindow = $this->makeWindow(startDays: 11, startHour: 14, durationHours: 2);

        $flightRequest->update([
            'departure_datetime' => $holdWindow['departure'],
            'return_datetime' => $holdWindow['return'],
            'departure_date' => $holdWindow['departure_date'],
            'departure_time' => $holdWindow['departure_time'],
            'return_date' => $holdWindow['return_date'],
            'return_time' => $holdWindow['return_time'],
        ]);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold", $holdWindow['payload'])
            ->assertCreated();

        $reservation = Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $quote->provider_id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'quote_id' => $quote->id,
            'reservation_code' => 'PV-HOLD-001-B',
            'status' => 'pending_payment',
            'total_amount' => $quote->total,
            'currency' => 'USD',
        ]);

        $flightRequest->update([
            'departure_datetime' => $holdWindow['departure'],
            'return_datetime' => $holdWindow['return'],
        ]);

        $flightRequest->legs()->delete();
        $flightRequest->legs()->create([
            'leg_order' => 1,
            'origin' => 'MMTO',
            'destination' => 'MMMM',
            'departure_datetime' => $staleLegWindow['departure'],
            'arrival_datetime' => null,
            'passengers' => 1,
            'status' => 'scheduled',
        ]);

        Pago::query()->create([
            'user_id' => $client->id,
            'reservation_id' => $reservation->id,
            'flight_request_id' => $flightRequest->id,
            'payment_type' => 'reservation',
            'amount' => $quote->total,
            'currency' => 'USD',
            'provider' => 'manual',
            'transaction_reference' => 'pay_manual_hold_reuse',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        app(AircraftAvailabilityService::class)
            ->blockAircraftForPaidReservation($reservation->fresh(['flightRequest.legs', 'legs']));

        $bookedBlock = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'booked')
            ->sole();

        $this->assertSame($holdWindow['departure_datetime'], $bookedBlock->start_datetime->format('Y-m-d H:i:s'));
        $this->assertSame($holdWindow['return_datetime'], $bookedBlock->end_datetime->format('Y-m-d H:i:s'));
    }

    public function test_booked_block_excludes_aircraft_but_non_overlapping_slots_allow_it(): void
    {
        $this->seed();
        $window = $this->makeWindow();

        [$client, , $quote, $aircraft, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD5');
        $reservation = Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $quote->provider_id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'quote_id' => $quote->id,
            'reservation_code' => 'PV-HOLD-002',
            'status' => 'confirmed',
            'total_amount' => $quote->total,
            'currency' => 'USD',
            'confirmed_at' => now(),
        ]);

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $aircraft->id,
            'quote_id' => $quote->id,
            'flight_request_id' => $flightRequest->id,
            'user_id' => $client->id,
            'reservation_id' => $reservation->id,
            'block_type' => 'confirmed_flight',
            'start_datetime' => $window['departure'],
            'end_datetime' => $window['return'],
            'status' => 'booked',
            'payment_status' => 'paid',
            'source' => 'test',
            'reason' => 'Booked test',
        ]);

        $overlapResponse = $this->postJson('/api/v1/client/quotes/preview', $this->previewPayload());
        $this->assertNotContains($aircraft->id, collect($overlapResponse->json('matches'))->pluck('aircraft_id')->all());

        $beforeResponse = $this->postJson('/api/v1/client/quotes/preview', $this->previewPayload(
            $window['departure']->copy()->subHours(4)->format('Y-m-d H:i:s'),
            $window['departure']->copy()->subMinutes(30)->format('Y-m-d H:i:s'),
        ));
        $afterResponse = $this->postJson('/api/v1/client/quotes/preview', $this->previewPayload(
            $window['return']->copy()->addHour()->format('Y-m-d H:i:s'),
            $window['return']->copy()->addHours(4)->format('Y-m-d H:i:s'),
        ));

        $this->assertContains($aircraft->id, collect($beforeResponse->json('matches'))->pluck('aircraft_id')->all());
        $this->assertContains($aircraft->id, collect($afterResponse->json('matches'))->pluck('aircraft_id')->all());
    }

    public function test_partial_overlap_excludes_only_the_conflicting_aircraft_and_other_aircraft_remains_available(): void
    {
        $this->seed();
        $window = $this->makeWindow();

        [$client, , $quote, $aircraftA, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD6A');
        $provider = Proveedor::query()->findOrFail($quote->provider_id);
        $aircraftB = $this->createAircraft($provider, 'XA-HOLD6B');

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $aircraftA->id,
            'quote_id' => $quote->id,
            'flight_request_id' => $flightRequest->id,
            'user_id' => $client->id,
            'block_type' => 'confirmed_flight',
            'start_datetime' => $window['departure']->copy()->addMinutes(90),
            'end_datetime' => $window['return']->copy()->addHours(2),
            'status' => 'booked',
            'payment_status' => 'paid',
            'source' => 'test',
            'reason' => 'Partial overlap',
        ]);

        $response = $this->postJson('/api/v1/client/quotes/preview', $this->previewPayload());
        $ids = collect($response->json('matches'))->pluck('aircraft_id')->all();

        $this->assertNotContains($aircraftA->id, $ids);
        $this->assertContains($aircraftB->id, $ids);
    }

    public function test_cancellation_and_checkout_cancellation_release_availability(): void
    {
        $this->seed();

        [$client, $token, $quote, $aircraft, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD7');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated();

        $reservation = Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $quote->provider_id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'quote_id' => $quote->id,
            'reservation_code' => 'PV-HOLD-007',
            'status' => 'pending_payment',
            'total_amount' => $quote->total,
            'currency' => 'USD',
        ]);

        AircraftAvailabilityBlock::query()->where('quote_id', $quote->id)->update(['reservation_id' => $reservation->id]);

        Pago::query()->create([
            'user_id' => $client->id,
            'reservation_id' => $reservation->id,
            'flight_request_id' => $flightRequest->id,
            'payment_type' => 'reservation',
            'amount' => $quote->total,
            'currency' => 'USD',
            'provider' => 'stripe',
            'transaction_reference' => 'cs_hold_cancel',
            'stripe_checkout_session_id' => 'cs_hold_cancel',
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertOk();

        $response = $this->postJson('/api/v1/client/quotes/preview', $this->previewPayload());
        $this->assertContains($aircraft->id, collect($response->json('matches'))->pluck('aircraft_id')->all());
    }

    public function test_second_client_cannot_take_same_aircraft_during_valid_hold(): void
    {
        $this->seed();

        [$clientA, $tokenA, $quoteA, $aircraft, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD8');
        [$clientB, $tokenB, $quoteB] = $this->createAcceptedQuoteForSameAircraftAndFlight($aircraft, $flightRequest);

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson("/api/v1/cliente/cotizaciones/{$quoteA->id}/aircraft-hold")
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson("/api/v1/cliente/cotizaciones/{$quoteB->id}/aircraft-hold")
            ->assertStatus(409);
    }

    public function test_inactive_aircraft_never_appears_and_round_trip_window_is_filtered(): void
    {
        $this->seed();
        $window = $this->makeWindow();

        $provider = $this->createProvider();
        $inactiveAircraft = $this->createAircraft($provider, 'XA-HOLD9A', ['status' => 'inactive']);
        $activeAircraft = $this->createAircraft($provider, 'XA-HOLD9B');

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $activeAircraft->id,
            'block_type' => 'confirmed_flight',
            'start_datetime' => $window['departure']->copy()->subHour(),
            'end_datetime' => $window['return']->copy()->addHours(5),
            'status' => 'booked',
            'payment_status' => 'paid',
            'source' => 'test',
            'reason' => 'Round trip occupied',
        ]);

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            ...$this->previewPayload(),
            'trip_type' => 'round_trip',
            'return_datetime' => $window['return']->copy()->addHours(4)->format('Y-m-d H:i:s'),
            'round_trip' => true,
        ]);

        $ids = collect($response->json('matches'))->pluck('aircraft_id')->all();
        $this->assertNotContains($inactiveAircraft->id, $ids);
        $this->assertNotContains($activeAircraft->id, $ids);
    }

    public function test_trial_active_aircraft_can_be_held_and_hold_payload_exposes_remaining_time(): void
    {
        $this->seed();

        [, $token, $quote] = $this->createAcceptedQuoteContext('XA-HOLD10');
        $quote->aircraft()->update(['status' => 'trial_active']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated()
            ->assertJsonPath('data.status', 'held');

        $this->assertGreaterThan(0, (int) $response->json('data.expires_in_seconds'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertOk()
            ->assertJsonPath('data.hold_id', $response->json('data.hold_id'))
            ->assertJsonPath('data.is_active', true);
    }

    public function test_payment_availability_allows_checkout_with_active_hold(): void
    {
        $this->seed();

        [$client, $token, $quote, $aircraft, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD12');
        $reservation = $this->createPendingReservation($client, $quote, $aircraft, $flightRequest);

        $holdResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/cliente/reservas/{$reservation->id}/payment-availability")
            ->assertOk()
            ->assertJsonPath('can_pay', true)
            ->assertJsonPath('hold_valid', true)
            ->assertJsonPath('reservation_booked', false)
            ->assertJsonPath('hold.id', $holdResponse->json('data.hold_id'))
            ->assertJsonPath('invalid_reason', null);
    }

    public function test_payment_availability_recovers_checkout_when_hold_expired_but_aircraft_is_still_free(): void
    {
        $this->seed();

        [$client, $token, $quote, $aircraft, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD13');
        $reservation = $this->createPendingReservation($client, $quote, $aircraft, $flightRequest);

        $holdResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated();

        AircraftAvailabilityBlock::query()->whereKey($holdResponse->json('data.hold_id'))->update([
            'hold_expires_at' => now()->subMinute(),
        ]);

        $availabilityResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/cliente/reservas/{$reservation->id}/payment-availability")
            ->assertOk()
            ->assertJsonPath('can_pay', true)
            ->assertJsonPath('hold_valid', true)
            ->assertJsonPath('reservation_booked', false)
            ->assertJsonPath('invalid_reason', null);

        $this->assertDatabaseHas('aircraft_availability_blocks', [
            'id' => $holdResponse->json('data.hold_id'),
            'reservation_id' => $reservation->id,
            'status' => 'held',
        ]);
        $this->assertNotEmpty($availabilityResponse->json('hold.expires_at'));
    }

    public function test_payment_availability_allows_checkout_when_own_reservation_is_already_booked(): void
    {
        $this->seed();

        [$client, $token, $quote, $aircraft, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD14');
        $holdResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated();

        $reservation = $this->createPendingReservation($client, $quote, $aircraft, $flightRequest);
        $hold = AircraftAvailabilityBlock::query()->findOrFail($holdResponse->json('data.hold_id'));
        $hold->update([
            'hold_expires_at' => now()->subMinute(),
        ]);

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $aircraft->id,
            'quote_id' => $quote->id,
            'flight_request_id' => $flightRequest->id,
            'reservation_id' => $reservation->id,
            'user_id' => $client->id,
            'block_type' => 'confirmed_flight',
            'start_datetime' => $hold->start_datetime,
            'end_datetime' => $hold->end_datetime,
            'status' => 'booked',
            'payment_status' => 'paid',
            'source' => 'reservation_payment_confirmed',
            'reason' => 'Reserva confirmada para la misma ventana.',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/cliente/reservas/{$reservation->id}/payment-availability")
            ->assertOk()
            ->assertJsonPath('can_pay', true)
            ->assertJsonPath('hold_valid', false)
            ->assertJsonPath('reservation_booked', true)
            ->assertJsonPath('invalid_reason', null);
    }

    public function test_payment_availability_detects_booked_block_from_other_reservation(): void
    {
        $this->seed();

        [$client, $token, $quote, $aircraft, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD15');
        $reservation = $this->createPendingReservation($client, $quote, $aircraft, $flightRequest);

        $holdResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertCreated();

        $hold = AircraftAvailabilityBlock::query()->findOrFail($holdResponse->json('data.hold_id'));

        $otherClient = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => uniqid('clientc.', true).'@test.dev',
        ]);
        $otherFlightRequest = $this->createFlightRequest($otherClient, $aircraft->provider, $aircraft);
        $otherQuote = $this->createAcceptedQuote($otherFlightRequest, $aircraft->provider, $aircraft);
        $otherReservation = $this->createPendingReservation($otherClient, $otherQuote, $aircraft, $otherFlightRequest);

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $aircraft->id,
            'quote_id' => $otherQuote->id,
            'flight_request_id' => $otherFlightRequest->id,
            'reservation_id' => $otherReservation->id,
            'user_id' => $otherClient->id,
            'block_type' => 'confirmed_flight',
            'start_datetime' => $hold->start_datetime,
            'end_datetime' => $hold->end_datetime,
            'status' => 'booked',
            'payment_status' => 'paid',
            'source' => 'reservation_payment_confirmed',
            'reason' => 'Otra reserva confirmada.',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/cliente/reservas/{$reservation->id}/payment-availability")
            ->assertOk()
            ->assertJsonPath('can_pay', false)
            ->assertJsonPath('invalid_reason', 'aircraft_booked_by_other_reservation')
            ->assertJsonPath('availability.available', false);
    }

    public function test_payment_availability_rejects_reservation_without_schedule(): void
    {
        $this->seed();

        [$client, $token, $quote, $aircraft, $flightRequest] = $this->createAcceptedQuoteContext('XA-HOLD16');
        $flightRequest->update([
            'departure_datetime' => null,
            'return_datetime' => null,
            'departure_date' => null,
            'departure_time' => null,
            'return_date' => null,
            'return_time' => null,
        ]);
        $reservation = $this->createPendingReservation($client, $quote, $aircraft, $flightRequest);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/cliente/reservas/{$reservation->id}/payment-availability")
            ->assertOk()
            ->assertJsonPath('can_pay', false)
            ->assertJsonPath('invalid_reason', 'reservation_missing_schedule');
    }

    public function test_expired_quote_cannot_create_hold(): void
    {
        $this->seed();

        [, $token, $quote] = $this->createAcceptedQuoteContext('XA-HOLD11');
        $quote->update(['expires_at' => now()->subMinute()]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/cliente/cotizaciones/{$quote->id}/aircraft-hold")
            ->assertStatus(409)
            ->assertSeeText('La cotizacion ya vencio');

        $this->assertDatabaseMissing('aircraft_availability_blocks', [
            'quote_id' => $quote->id,
            'status' => 'held',
        ]);
    }

    private function createAcceptedQuoteContext(string $registration): array
    {
        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => uniqid('client.', true).'@test.dev',
        ]);

        $provider = $this->createProvider();
        $aircraft = $this->createAircraft($provider, $registration);
        $flightRequest = $this->createFlightRequest($client, $provider, $aircraft);
        $quote = $this->createAcceptedQuote($flightRequest, $provider, $aircraft);

        return [$client, TokenApi::issue($client), $quote, $aircraft, $flightRequest];
    }

    private function createPendingReservation(
        Usuario $client,
        Cotizacion $quote,
        Aeronave $aircraft,
        SolicitudVuelo $flightRequest,
    ): Reserva {
        return Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $quote->provider_id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'quote_id' => $quote->id,
            'reservation_code' => 'PV-'.now()->format('ymd').'-TEST'.random_int(100, 999),
            'status' => 'pending_payment',
            'total_amount' => $quote->total,
            'currency' => 'USD',
        ]);
    }

    private function createAcceptedQuoteForSameAircraftAndFlight(Aeronave $aircraft, SolicitudVuelo $flightRequest): array
    {
        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => uniqid('clientb.', true).'@test.dev',
        ]);

        $secondFlightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => $flightRequest->origin,
            'destination' => $flightRequest->destination,
            'departure_datetime' => $flightRequest->departure_datetime,
            'return_datetime' => $flightRequest->return_datetime,
            'departure_date' => optional($flightRequest->departure_datetime)->toDateString(),
            'departure_time' => optional($flightRequest->departure_datetime)->format('H:i'),
            'return_date' => optional($flightRequest->return_datetime)->toDateString(),
            'return_time' => optional($flightRequest->return_datetime)->format('H:i'),
            'passengers' => $flightRequest->passengers,
            'trip_type' => $flightRequest->trip_type,
            'assigned_provider_id' => $flightRequest->assigned_provider_id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'currency' => 'USD',
            'final_price' => 12100,
            'status' => 'quoted',
            'workflow_status' => 'cotizada',
        ]);

        $quote = Cotizacion::query()->create([
            'flight_request_id' => $secondFlightRequest->id,
            'provider_id' => $aircraft->provider_id,
            'aircraft_id' => $aircraft->id,
            'subtotal' => 10000,
            'taxes' => 1600,
            'fees' => 500,
            'total' => 12100,
            'currency' => 'USD',
            'status' => 'accepted',
            'expires_at' => now()->addDays(2),
        ]);

        return [$client, TokenApi::issue($client), $quote];
    }

    private function createProvider(): Proveedor
    {
        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => uniqid('provider.', true).'@test.dev',
        ]);

        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Provider Holds',
            'commercial_name' => 'Provider Holds',
            'approval_status' => 'approved',
        ]);

        $providerUser->forceFill(['provider_id' => $provider->id])->save();

        return $provider;
    }

    private function createAircraft(Proveedor $provider, string $registration, array $overrides = []): Aeronave
    {
        return Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation XLS+',
            'registration' => $registration,
            'category' => 'Light Jet',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 5000,
            'currency' => 'USD',
            'status' => 'active',
            ...$overrides,
        ]);
    }

    private function createFlightRequest(Usuario $client, Proveedor $provider, Aeronave $aircraft): SolicitudVuelo
    {
        $window = $this->makeWindow();

        return SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => $window['departure'],
            'return_datetime' => $window['return'],
            'departure_date' => $window['departure_date'],
            'departure_time' => $window['departure_time'],
            'return_date' => $window['return_date'],
            'return_time' => $window['return_time'],
            'passengers' => 4,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'currency' => 'USD',
            'final_price' => 12100,
            'status' => 'quoted',
            'workflow_status' => 'cotizada',
        ]);
    }

    private function createAcceptedQuote(SolicitudVuelo $flightRequest, Proveedor $provider, Aeronave $aircraft): Cotizacion
    {
        return Cotizacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'subtotal' => 10000,
            'taxes' => 1600,
            'fees' => 500,
            'total' => 12100,
            'currency' => 'USD',
            'status' => 'accepted',
            'expires_at' => now()->addDays(2),
        ]);
    }

    private function previewPayload(?string $departure = null, ?string $return = null): array
    {
        $window = $this->makeWindow();

        return [
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => $departure ?: $window['departure_datetime'],
            'return_datetime' => $return ?: $window['return_datetime'],
            'passengers' => 4,
            'trip_type' => 'one_way',
        ];
    }

    private function makeWindow(int $startDays = 30, int $startHour = 10, int $durationHours = 4): array
    {
        $departure = now()->copy()->addDays($startDays)->setTime($startHour, 0, 0);
        $return = $departure->copy()->addHours($durationHours);

        return [
            'departure' => $departure,
            'return' => $return,
            'departure_datetime' => $departure->format('Y-m-d H:i:s'),
            'return_datetime' => $return->format('Y-m-d H:i:s'),
            'departure_date' => $departure->toDateString(),
            'departure_time' => $departure->format('H:i'),
            'return_date' => $return->toDateString(),
            'return_time' => $return->format('H:i'),
            'payload' => [
                'departure_datetime' => $departure->format('Y-m-d H:i:s'),
                'return_datetime' => $return->format('Y-m-d H:i:s'),
                'departure_date' => $departure->toDateString(),
                'departure_time' => $departure->format('H:i'),
                'return_date' => $return->toDateString(),
                'return_time' => $return->format('H:i'),
            ],
        ];
    }

    private function invokePrivateWebhookMethod(string $methodName, object $payload): mixed
    {
        $method = new ReflectionMethod(StripeWebhookControlador::class, $methodName);
        $method->setAccessible(true);

        return $method->invoke(new StripeWebhookControlador, $payload);
    }
}
