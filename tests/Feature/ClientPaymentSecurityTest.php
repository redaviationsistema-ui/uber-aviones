<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\ContratoReserva;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\RegistroAuditoria;
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
            'status' => 'signed',
            'signed_pdf_path' => 'contracts/reservations/test.pdf',
            'signed_at' => now(),
        ]);

        $token = TokenApi::issue($user, 'test-client-payment');

        return compact('user', 'token', 'provider', 'aircraft', 'flightRequest', 'reservation');
    }
}
