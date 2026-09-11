<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\ContratoReserva;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use App\Servicios\Contratos\ContratoPdfServicio;
use App\Servicios\Contratos\DocuSignServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DocuSignEnvelopeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public static function activeEnvelopeStatusesProvider(): array
    {
        return [
            'created' => ['created', false],
            'created regenerate' => ['created', true],
            'sent' => ['sent', false],
            'sent regenerate' => ['sent', true],
            'delivered' => ['delivered', false],
            'delivered regenerate' => ['delivered', true],
            'signing' => ['signing', false],
            'signing regenerate' => ['signing', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activeEnvelopeStatusesProvider')]
    public function test_active_envelope_state_is_reused_without_creating_a_new_one(string $status, bool $regenerate): void
    {
        $context = $this->createContractContext();
        $context['contract']->update([
            'status' => 'sent',
            'docusign_status' => $status,
            'docusign_envelope_id' => 'env-active-'.$status,
            'completed_at' => null,
        ]);

        $this->bindDocuSignFakes(createTimes: 0, recipientViewTimes: 1);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/contrato/docusign', ['regenerate' => $regenerate])
            ->assertOk()
            ->assertJsonPath('envelope_id', 'env-active-'.$status);

        $this->assertSame('env-active-'.$status, $context['contract']->fresh()->docusign_envelope_id);
        $this->assertSame($status, $context['contract']->fresh()->docusign_status);
    }

    public static function completedProvider(): array
    {
        return [[false, 'completed', true], [true, 'completed', true], [true, 'completed', false], [true, 'sent', true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('completedProvider')]
    public function test_completed_contract_never_creates_new_envelope_even_with_regenerate(bool $regenerate, string $status, bool $completedAt): void
    {
        $context = $this->createContractContext();
        $context['contract']->update([
            'status' => 'completed',
            'docusign_status' => $status,
            'docusign_envelope_id' => 'env-completed-001',
            'completed_at' => $completedAt ? now() : null,
            'signed_pdf_path' => 'signed/evidence.pdf',
            'terms_snapshot' => ['evidence' => 'unchanged'],
        ]);

        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('signed/evidence.pdf', '%PDF-signed-evidence');
        $before = $context['contract']->fresh()->getAttributes();
        $this->bindDocuSignFakes(createTimes: 0, recipientViewTimes: 0);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/contrato/docusign', [
                'regenerate' => $regenerate,
            ])
            ->assertStatus(422);

        $this->assertSame('env-completed-001', $context['contract']->fresh()->docusign_envelope_id);
        $this->assertSame($status, $context['contract']->fresh()->docusign_status);
        $this->assertSame($before, $context['contract']->fresh()->getAttributes());
        $this->assertSame('%PDF-signed-evidence', \Illuminate\Support\Facades\Storage::disk('local')->get('signed/evidence.pdf'));
    }

    public static function terminalStatusesProvider(): array
    {
        return [
            'expired' => ['expired'],
            'voided' => ['voided'],
            'declined' => ['declined'],
            'error' => ['error'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalStatusesProvider')]
    public function test_terminal_envelope_without_regenerate_does_not_create_new_one(string $status): void
    {
        $context = $this->createContractContext();
        $context['contract']->update([
            'status' => 'sent',
            'docusign_status' => $status,
            'docusign_envelope_id' => 'env-terminal-'.$status,
            'completed_at' => null,
        ]);

        $this->bindDocuSignFakes(createTimes: 0, recipientViewTimes: 0);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/contrato/docusign')
            ->assertStatus(422);

        $this->assertSame('env-terminal-'.$status, $context['contract']->fresh()->docusign_envelope_id);
        $this->assertSame($status, $context['contract']->fresh()->docusign_status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalStatusesProvider')]
    public function test_terminal_envelope_with_regenerate_creates_exactly_one_new_envelope(string $status): void
    {
        $context = $this->createContractContext();
        $context['contract']->update([
            'status' => 'sent',
            'docusign_status' => $status,
            'docusign_envelope_id' => 'env-terminal-'.$status,
            'completed_at' => null,
        ]);

        $this->bindDocuSignFakes(createTimes: 1, recipientViewTimes: 2, newEnvelopeId: 'env-regenerated-'.$status, requestTimes: 2);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/contrato/docusign', [
                'regenerate' => true,
            ])
            ->assertOk()
            ->assertJsonPath('envelope_id', 'env-regenerated-'.$status);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/reservas/'.$context['reservation']->id.'/contrato/docusign', ['regenerate' => true])
            ->assertOk()->assertJsonPath('envelope_id', 'env-regenerated-'.$status);

        $this->assertSame('env-regenerated-'.$status, $context['contract']->fresh()->docusign_envelope_id);
        $this->assertSame('sent', $context['contract']->fresh()->docusign_status);
    }

    public function test_recipient_view_failure_preserves_envelope_for_retry(): void
    {
        $context = $this->createContractContext();
        $docuSign = Mockery::mock(DocuSignServicio::class);
        $docuSign->shouldReceive('estaConfigurado')->twice()->andReturnTrue();
        $docuSign->shouldReceive('construirReturnUrl')->twice()->andReturn('https://example.test/return');
        $docuSign->shouldReceive('configurationDiagnostics')->andReturn([]);
        $docuSign->shouldReceive('runtimeDiagnosticsFromException')->andReturn([]);
        $docuSign->shouldReceive('crearEnvelopeParaFirmaEmbebida')->once()->andReturn('env-view-retry');
        $docuSign->shouldReceive('crearRecipientView')->once()->ordered()->andThrow(new \RuntimeException('Recipient view unavailable'));
        $docuSign->shouldReceive('crearRecipientView')->once()->ordered()->andReturn('https://example.test/sign');
        $this->app->instance(DocuSignServicio::class, $docuSign);
        $pdf = Mockery::mock(ContratoPdfServicio::class);
        $pdf->shouldReceive('guardarContratoReserva')->once()->andReturn('contracts/retry.pdf');
        $pdf->shouldReceive('rutaAbsoluta')->andReturn('/tmp/nonexistent-retry.pdf');
        $this->app->instance(ContratoPdfServicio::class, $pdf);

        $path = '/api/v1/cliente/reservas/'.$context['reservation']->id.'/contrato/docusign';
        $this->withHeader('Authorization', 'Bearer '.$context['token'])->postJson($path)->assertStatus(422);
        $this->assertSame('env-view-retry', $context['contract']->fresh()->docusign_envelope_id);
        $this->withHeader('Authorization', 'Bearer '.$context['token'])->postJson($path)
            ->assertOk()->assertJsonPath('envelope_id', 'env-view-retry');
    }

    private function bindDocuSignFakes(int $createTimes, int $recipientViewTimes, string $newEnvelopeId = 'env-new', int $requestTimes = 1): void
    {
        $docuSign = Mockery::mock(DocuSignServicio::class);
        $docuSign->shouldReceive('estaConfigurado')->times($requestTimes)->andReturnTrue();
        $docuSign->shouldReceive('construirReturnUrl')->andReturn('https://example.test/contract-return');
        $docuSign->shouldReceive('configurationDiagnostics')->andReturn(['ok' => true, 'checks' => [], 'missing' => []]);
        $docuSign->shouldReceive('runtimeDiagnosticsFromException')->andReturn([]);

        $createExpectation = $docuSign->shouldReceive('crearEnvelopeParaFirmaEmbebida');
        $createTimes > 0 ? $createExpectation->times($createTimes)->andReturn($newEnvelopeId) : $createExpectation->never();

        $recipientExpectation = $docuSign->shouldReceive('crearRecipientView');
        $recipientViewTimes > 0 ? $recipientExpectation->times($recipientViewTimes)->andReturn('https://example.test/sign') : $recipientExpectation->never();

        $this->app->instance(DocuSignServicio::class, $docuSign);

        $pdf = Mockery::mock(ContratoPdfServicio::class);
        $pdf->shouldReceive('guardarContratoReserva')->andReturn('contracts/reservations/fake-lifecycle.pdf');
        $pdf->shouldReceive('rutaAbsoluta')->andReturn('/tmp/uberaviones-nonexistent-fake.pdf');
        $this->app->instance(ContratoPdfServicio::class, $pdf);
    }

    /**
     * @return array{user: Usuario, token: string, reservation: Reserva, contract: ContratoReserva}
     */
    private function createContractContext(): array
    {
        $client = Usuario::factory()->create(['role' => Usuario::ROLE_CLIENT, 'status' => 'active']);
        $providerUser = Usuario::factory()->create(['role' => Usuario::ROLE_PROVIDER, 'status' => 'active']);
        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'DocuSign Lifecycle Provider',
            'commercial_name' => 'DocuSign Lifecycle Provider',
            'approval_status' => 'approved',
        ]);
        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation Lifecycle',
            'registration' => 'XA-'.strtoupper(substr(uniqid(), -7)),
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
            'departure_datetime' => now()->addDays(5),
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
            'reservation_code' => 'PV-LIFECYCLE-'.$flightRequest->id,
            'status' => 'pending_payment',
            'total_amount' => 15000,
            'currency' => 'USD',
        ]);
        $contract = ContratoReserva::query()->create([
            'reservation_id' => $reservation->id,
            'contract_code' => 'CTR-LIFECYCLE-'.$flightRequest->id,
            'status' => 'generated',
            'signer_name' => $client->name,
            'signer_email' => $client->email,
        ]);

        $token = TokenApi::issue($client, 'test-docusign-lifecycle');

        return [
            'user' => $client,
            'token' => $token,
            'reservation' => $reservation->fresh(['contract']),
            'contract' => $contract,
        ];
    }
}
