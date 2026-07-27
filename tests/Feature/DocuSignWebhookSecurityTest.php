<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\ContratoReserva;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use App\Servicios\Contratos\ContratoPdfServicio;
use App\Servicios\Contratos\DocuSignServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DocuSignWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_non_completed_signed_events_never_enable_payment(): void
    {
        $contract = $this->createContractContext('env-statuses');

        foreach (['sent', 'delivered', 'declined', 'voided'] as $status) {
            $this->signedWebhook([
                'data' => ['envelopeId' => 'env-statuses', 'status' => $status],
            ])->assertOk();

            $contract->refresh();
            $this->assertSame($status, $contract->docusign_status);
            $this->assertSame('generated', $contract->status);
            $this->assertNull($contract->completed_at);
            $this->assertDatabaseMissing('payments', ['reservation_id' => $contract->reservation_id]);
        }
    }

    public function test_completed_event_is_applied_once_and_unknown_envelope_is_ignored(): void
    {
        $contract = $this->createContractContext('env-completed');
        $docuSign = Mockery::mock(DocuSignServicio::class);
        $docuSign->shouldReceive('descargarPdfCombinado')->once()->with('env-completed')->andReturn('%PDF-test');
        $pdf = Mockery::mock(ContratoPdfServicio::class);
        $pdf->shouldReceive('guardarPdfFirmado')->once()->andReturn('contracts/signed/test.pdf');
        $this->app->instance(DocuSignServicio::class, $docuSign);
        $this->app->instance(ContratoPdfServicio::class, $pdf);

        $payload = ['data' => ['envelopeId' => 'env-completed', 'status' => 'completed']];
        $this->signedWebhook($payload)->assertOk();
        $this->signedWebhook($payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $contract->refresh();
        $this->assertSame('completed', $contract->status);
        $this->assertSame('completed', $contract->docusign_status);
        $this->assertNotNull($contract->completed_at);
        $this->assertDatabaseCount('payments', 1);

        $this->signedWebhook([
            'data' => ['envelopeId' => 'env-unknown', 'status' => 'completed'],
        ])->assertOk()->assertJsonPath('received', true);
    }

    public function test_incorrect_docusign_signature_is_rejected(): void
    {
        config()->set('services.docusign.webhook_secret', 'docusign-test-secret');

        $this->call(
            'POST',
            '/api/v1/public/docusign/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_DOCUSIGN_SIGNATURE_1' => 'invalid',
            ],
            json_encode(['data' => ['envelopeId' => 'env-forged', 'status' => 'completed']], JSON_THROW_ON_ERROR),
        )
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
    }

    private function signedWebhook(array $payload)
    {
        $secret = 'docusign-test-secret';
        config()->set('services.docusign.webhook_secret', $secret);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $json, $secret, true));

        return $this->call(
            'POST',
            '/api/v1/public/docusign/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_DOCUSIGN_SIGNATURE_1' => $signature,
            ],
            $json,
        );
    }

    private function createContractContext(string $envelopeId): ContratoReserva
    {
        $client = Usuario::factory()->create(['role' => Usuario::ROLE_CLIENT, 'status' => 'active']);
        $providerUser = Usuario::factory()->create(['role' => Usuario::ROLE_PROVIDER, 'status' => 'active']);
        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'DocuSign Test',
            'commercial_name' => 'DocuSign Test',
            'approval_status' => 'approved',
        ]);
        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation DocuSign',
            'registration' => 'XA-DOCS',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 5000,
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => now()->addDays(3),
            'passengers' => 2,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'final_price' => 15000,
            'currency' => 'USD',
            'status' => 'reserved',
        ]);
        $reservation = Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'reservation_code' => 'PV-DOCS-'.$flightRequest->id,
            'status' => 'pending_payment',
            'total_amount' => 15000,
            'currency' => 'USD',
        ]);

        return ContratoReserva::query()->create([
            'reservation_id' => $reservation->id,
            'contract_code' => 'CTR-DOCS-'.$flightRequest->id,
            'status' => 'generated',
            'docusign_envelope_id' => $envelopeId,
            'docusign_status' => 'draft',
        ]);
    }
}
