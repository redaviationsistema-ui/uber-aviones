<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\ContratoReserva;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\Usuario;
use Carbon\Carbon;
use Tests\TestCase;

class ContractPdfViewTest extends TestCase
{
    public function test_contract_pdf_view_renders_reference_style_with_dynamic_data(): void
    {
        $client = new Usuario([
            'name' => 'Jose Luis Hernandez',
            'email' => 'jose@example.com',
        ]);

        $provider = new Proveedor([
            'commercial_name' => 'Red Aviation',
            'company_name' => 'Red Aviation Company',
        ]);

        $aircraft = new Aeronave([
            'model' => 'Learjet 31A',
            'category' => 'Light Jet',
        ]);

        $reservation = new Reserva([
            'id' => 15,
            'reservation_code' => 'PV-260703-LOSJOS',
            'total_amount' => 2931.50,
            'currency' => 'USD',
        ]);
        $reservation->setRelation('client', $client);
        $reservation->setRelation('provider', $provider);
        $reservation->setRelation('aircraft', $aircraft);

        $contract = new ContratoReserva([
            'id' => 7,
            'contract_code' => 'CTR-260703-9HDVXN',
            'generated_at' => Carbon::parse('2026-07-03 12:00:00'),
        ]);

        $html = view('pdf.contract', [
            'contract' => $contract,
            'reservation' => $reservation,
            'snapshot' => [],
            'clientSnapshot' => [],
            'conditions' => [
                'Pago requerido antes de confirmacion final.',
                'Operacion sujeta a condiciones de seguridad y slot.',
            ],
            'segments' => [
                [
                    'order' => 1,
                    'origin' => 'MMTO',
                    'destination' => 'MMQT',
                    'departure' => '2026-07-03 15:00:00',
                ],
            ],
            'route' => 'MMTO + MMQT',
            'departureDate' => '03/07/2026 15:00',
            'aircraft' => 'Learjet 31A',
            'aircraftCategory' => 'Light Jet',
            'passengers' => '1 pasajero',
            'customerName' => 'Jose Luis Hernandez',
            'customerRepresentative' => 'Jose Luis Hernandez',
            'customerAddress' => 'Domicilio por confirmar',
            'serviceTier' => 'Servicio ejecutivo privado',
            'operator' => 'Red Aviation',
            'contractDate' => '03/07/2026',
            'finalPrice' => '$2,931.50 USD',
            'depositText' => '$1,465.75 USD (50% del costo total)',
            'balanceText' => '$1,465.75 USD',
            'pricingRows' => [
                ['label' => 'Costo total del servicio', 'amount' => 2931.50],
                ['label' => 'Depósito requerido', 'amount' => 1465.75],
                ['label' => 'Saldo pendiente estimado', 'amount' => 1465.75],
            ],
            'logoPath' => public_path('logo.png'),
            'providerSignaturePath' => public_path('AUTOGRAFO/AUTOGRAFO JEFE.png'),
            'clientSignatureDataUrl' => '',
            'bankAccounts' => [
                [
                    'bank' => 'BANBAJIO',
                    'account' => '046 76313 20201',
                    'clabe' => '0304 209000 4337 2636',
                    'beneficiary' => 'TRANSPORTACION EXITOSA BELLIKAI S.A. DE C.V.',
                    'rfc' => 'TEB231030NU9',
                ],
                [
                    'bank' => 'BANREGIO',
                    'account' => '247 96234 0011',
                    'clabe' => '05842 0000 150761410',
                    'beneficiary' => 'TRANSPORTACION EXITOSA BELLIKAI S.A. DE C.V.',
                    'rfc' => 'TEB231030NU9',
                ],
                [
                    'bank' => 'BBVA',
                    'account' => '0122 912627',
                    'clabe' => '01243 800122 9126272',
                    'beneficiary' => 'TRANSPORTACION EXITOSA BELLIKAI S.A. DE C.V.',
                    'rfc' => 'TEB231030NU9',
                ],
            ],
            'includesItems' => [
                'Aeronave y tripulacion asignada para la ruta contratada.',
            ],
            'excludesItems' => [
                'Cambios de itinerario solicitados despues de la firma.',
            ],
        ])->render();

        $this->assertStringContainsString('Contrato de prestacion de servicios de aviacion ejecutiva', $html);
        $this->assertStringContainsString('ANEXO A — DATOS COMERCIALES DE LA RESERVA', $html);
        $this->assertStringContainsString('CTR-260703-9HDVXN', $html);
        $this->assertStringContainsString('Learjet 31A', $html);
        $this->assertStringContainsString('Jose Luis Hernandez', $html);
        $this->assertStringContainsString('Cuentas recaudadoras autorizadas', $html);
        $this->assertStringNotContainsString('Undefined variable', $html);
    }
}
