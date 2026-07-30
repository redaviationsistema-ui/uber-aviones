<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\ContratoReserva;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_cannot_force_manual_payment_to_paid(): void
    {
        $this->seed();

        $context = $this->createReservationPaymentContext();

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/pagar', [
                'status' => 'paid',
                'payment_status' => 'paid',
                'booking_status' => 'confirmed',
                'paid' => true,
                'confirmed' => true,
                'approved' => true,
                'payment_order' => ['status' => 'paid'],
                'transaction_reference' => 'manual-ref-123',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('reservation.status', 'pending_payment');

        $payment = Pago::query()->where('reservation_id', $context['reservation']->id)->latest('id')->firstOrFail();

        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->paid_at);
        $this->assertSame('manual-ref-123', $payment->transaction_reference);
        $this->assertSame('paid', data_get($payment->gateway_response, 'requested_terminal_state_ignored.status'));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $context['user']->id,
            'action' => 'manual_payment_submitted',
            'module' => 'reservation_payments',
        ]);
    }

    public function test_payment_retry_is_audited(): void
    {
        $this->seed();

        $context = $this->createReservationPaymentContext();

        $payment = Pago::query()->create([
            'user_id' => $context['user']->id,
            'reservation_id' => $context['reservation']->id,
            'payment_type' => 'reservation',
            'amount' => $context['reservation']->total_amount,
            'currency' => 'USD',
            'provider' => 'manual',
            'status' => 'failed',
            'failure_reason' => 'manual failure',
        ]);

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/reintentar-pago');

        $response
            ->assertOk()
            ->assertJsonPath('payment.status', 'pending');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $context['user']->id,
            'action' => 'payment_retry_requested',
            'module' => 'reservation_payments',
        ]);
    }

    public function test_client_cannot_confirm_reservation_payment(): void
    {
        $this->seed();
        $context = $this->createReservationPaymentContext();

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/pago/confirmar', [
                'payment_status' => 'paid',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CLIENT_PAYMENT_CONFIRMATION_FORBIDDEN');

        $this->assertDatabaseMissing('payments', [
            'reservation_id' => $context['reservation']->id,
            'status' => 'paid',
        ]);
    }

    public function test_client_cannot_declare_contract_signed(): void
    {
        $this->seed();
        $context = $this->createReservationPaymentContext();
        $context['reservation']->contract()->update([
            'status' => 'sent',
            'docusign_status' => 'sent',
            'completed_at' => null,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/contrato/firmar', [
                'contract_status' => 'signed',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CLIENT_CONTRACT_CONFIRMATION_FORBIDDEN');

        $this->assertDatabaseHas('reservation_contracts', [
            'reservation_id' => $context['reservation']->id,
            'status' => 'sent',
            'docusign_status' => 'sent',
        ]);
    }

    public function test_other_client_cannot_read_payment_authorization(): void
    {
        $this->seed();
        $context = $this->createReservationPaymentContext();
        $other = Usuario::factory()->create(['role' => Usuario::ROLE_CLIENT, 'status' => 'active']);
        $otherToken = TokenApi::issue($other, 'other-client');

        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/payment-authorization')
            ->assertForbidden();
    }

    public function test_pending_contract_blocks_payment_authorization(): void
    {
        $this->seed();
        $context = $this->createReservationPaymentContext();
        $context['reservation']->contract()->update([
            'status' => 'sent',
            'docusign_status' => 'sent',
            'completed_at' => null,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->getJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/payment-authorization')
            ->assertConflict()
            ->assertJsonPath('authorized', false)
            ->assertJsonFragment(['CONTRACT_NOT_COMPLETED']);
    }

    public function test_contract_regeneration_is_idempotent_with_same_key(): void
    {
        $this->seed();
        $context = $this->createReservationPaymentContext();
        $context['reservation']->contract()->update([
            'status' => 'sent',
            'docusign_status' => 'sent',
            'completed_at' => null,
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$context['token'],
            'Idempotency-Key' => 'contract:'.$context['reservation']->id,
        ];

        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/contrato/generar');
        $second = $this->withHeaders($headers)
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/contrato/generar');

        $first->assertOk();
        $second->assertOk()
            ->assertJsonPath('contract.id', $first->json('contract.id'));
        $this->assertSame(1, ContratoReserva::query()
            ->where('reservation_id', $context['reservation']->id)
            ->count());
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_contract_pending_reservation_store_does_not_revalidate_aircraft_availability(): void
    {
        $this->seed();
        $context = $this->createReservationPaymentContext();

        $context['reservation']->contract()->update([
            'status' => 'generated',
            'docusign_status' => 'generated',
            'completed_at' => null,
        ]);
        $context['flightRequest']->update([
            'workflow_status' => 'contrato pendiente',
        ]);

        $this->createConflictingAvailabilityBlock($context);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas', [
                'flight_request_id' => $context['flightRequest']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('reservation.id', $context['reservation']->id);
    }

    public function test_contract_pending_generation_keeps_current_state_even_if_aircraft_loses_availability(): void
    {
        $this->seed();
        $context = $this->createReservationPaymentContext();

        $context['reservation']->contract()->update([
            'status' => 'generated',
            'docusign_status' => 'generated',
            'completed_at' => null,
        ]);
        $context['flightRequest']->update([
            'workflow_status' => 'contrato pendiente',
        ]);

        $this->createConflictingAvailabilityBlock($context);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/contrato/generar')
            ->assertOk()
            ->assertJsonPath('contract.id', $context['reservation']->contract->id)
            ->assertJsonPath('contract.status', 'generated');
    }

    public function test_docusign_webhook_without_hmac_is_rejected(): void
    {
        config()->set('services.docusign.webhook_secret', 'test-secret');

        $this->postJson('/api/v1/public/docusign/webhook', [
            'data' => ['envelopeId' => 'env-forged', 'status' => 'completed'],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
    }

    /**
     * @return array{user: Usuario, token: string, provider: Proveedor, aircraft: Aeronave, flightRequest: SolicitudVuelo, reservation: Reserva}
     */
    private function createReservationPaymentContext(): array
    {
        $user = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
        ]);

        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
        ]);

        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Provider Manual Payment',
            'commercial_name' => 'Provider Manual Payment',
            'approval_status' => 'approved',
        ]);

        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation Secure',
            'category' => 'Light Jet',
            'registration' => 'XA-SECU1',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 5000,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $user->id,
            'origin' => 'MMMX',
            'destination' => 'MMUN',
            'departure_datetime' => now()->addDays(3)->setTime(10, 0),
            'departure_date' => now()->addDays(3)->format('Y-m-d'),
            'departure_time' => '10:00',
            'passengers' => 4,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'final_price' => 15000,
            'currency' => 'USD',
            'status' => 'reserved',
            'payment_status' => 'pending',
        ]);

        $reservation = Reserva::query()->create([
            'client_id' => $user->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'reservation_code' => 'PV-SECURE-001',
            'status' => 'pending_payment',
            'total_amount' => 15000,
            'currency' => 'USD',
        ]);

        ContratoReserva::query()->create([
            'reservation_id' => $reservation->id,
            'contract_code' => 'CTR-SECURE-001',
            'status' => 'completed',
            'docusign_status' => 'completed',
            'signed_pdf_path' => 'contracts/reservations/test.pdf',
            'signed_at' => now(),
            'completed_at' => now(),
        ]);

        $token = TokenApi::issue($user, 'test-client-payment');

        return compact('user', 'token', 'provider', 'aircraft', 'flightRequest', 'reservation');
    }

    /**
     * @param  array{user: Usuario, provider: Proveedor, aircraft: Aeronave, flightRequest: SolicitudVuelo}  $context
     */
    private function createConflictingAvailabilityBlock(array $context): void
    {
        $otherClient = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
        ]);

        $otherFlightRequest = SolicitudVuelo::query()->create([
            'client_id' => $otherClient->id,
            'origin' => 'MMMX',
            'destination' => 'MMUN',
            'departure_datetime' => $context['flightRequest']->departure_datetime->copy(),
            'departure_date' => $context['flightRequest']->departure_datetime->format('Y-m-d'),
            'departure_time' => $context['flightRequest']->departure_datetime->format('H:i'),
            'passengers' => 2,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $context['provider']->id,
            'assigned_aircraft_id' => $context['aircraft']->id,
            'final_price' => 12000,
            'currency' => 'USD',
            'status' => 'reserved',
        ]);

        $otherReservation = Reserva::query()->create([
            'client_id' => $otherClient->id,
            'provider_id' => $context['provider']->id,
            'aircraft_id' => $context['aircraft']->id,
            'flight_request_id' => $otherFlightRequest->id,
            'reservation_code' => 'PV-CONFLICT-001',
            'status' => 'pending_payment',
            'total_amount' => 12000,
            'currency' => 'USD',
        ]);

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $context['aircraft']->id,
            'reservation_id' => $otherReservation->id,
            'block_type' => 'booked',
            'status' => 'active',
            'start_datetime' => $context['flightRequest']->departure_datetime->copy()->subHour(),
            'end_datetime' => $context['flightRequest']->departure_datetime->copy()->addHours(2),
            'reason' => 'Conflicto posterior a la generacion del contrato',
        ]);
    }
}
