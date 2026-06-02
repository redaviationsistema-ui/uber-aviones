<?php

namespace App\Http\Controladores;

use App\Modelos\ContratoReserva;
use App\Servicios\Contratos\ContratoPdfServicio;
use App\Servicios\Contratos\ContratoReservaServicio;
use App\Servicios\Contratos\DocuSignServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DocuSignWebhookControlador extends ControladorBase
{
    public function handle(
        Request $request,
        DocuSignServicio $docuSignServicio,
        ContratoPdfServicio $contratoPdfServicio,
        ContratoReservaServicio $contratoReservaServicio,
    ) {
        $payload = $request->all();
        $contract = ContratoReserva::query()
            ->where('docusign_envelope_id', $this->extractEnvelopeId($payload))
            ->with(['reservation.client', 'reservation.flightRequest', 'reservation.payments'])
            ->first();

        if (! $contract) {
            return response()->json(['success' => true, 'received' => true]);
        }

        $docusignStatus = $this->extractStatus($payload);

        $contract->update([
            'docusign_status' => $docusignStatus ?: $contract->docusign_status,
            'last_webhook_payload' => $payload,
        ]);

        if ($docusignStatus !== 'completed') {
            return response()->json(['success' => true, 'received' => true]);
        }

        try {
            $signedPdf = $docuSignServicio->descargarPdfCombinado((string) $contract->docusign_envelope_id);
            $signedPdfPath = $contratoPdfServicio->guardarPdfFirmado(
                (int) $contract->reservation_id,
                (string) $contract->docusign_envelope_id,
                $signedPdf
            );

            $termsSnapshot = is_array($contract->terms_snapshot) ? $contract->terms_snapshot : [];
            $termsSnapshot['docusign'] = [
                'completed_via' => 'webhook',
                'status' => $docusignStatus,
                'completed_at' => now()->toIso8601String(),
            ];

            $paymentOrder = $contratoReservaServicio->registrarFirma(
                $contract->reservation,
                $contract,
                $contract->reservation->client,
                $termsSnapshot,
                $signedPdfPath,
                $docusignStatus
            );

            return response()->json([
                'success' => true,
                'received' => true,
                'contract_id' => $contract->id,
                'payment_order_id' => $paymentOrder->id,
            ]);
        } catch (Throwable $exception) {
            Log::warning('No fue posible completar el webhook de DocuSign.', [
                'contract_id' => $contract->id,
                'envelope_id' => $contract->docusign_envelope_id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No fue posible procesar el webhook de DocuSign.',
            ], 500);
        }
    }

    private function extractEnvelopeId(array $payload): string
    {
        return (string) (
            data_get($payload, 'data.envelopeId')
            ?? data_get($payload, 'envelopeId')
            ?? data_get($payload, 'envelopeSummary.envelopeId')
            ?? ''
        );
    }

    private function extractStatus(array $payload): string
    {
        $status = Str::lower((string) (
            data_get($payload, 'data.status')
            ?? data_get($payload, 'status')
            ?? data_get($payload, 'envelopeSummary.status')
            ?? data_get($payload, 'event')
            ?? ''
        ));

        if (Str::contains($status, 'complete')) {
            return 'completed';
        }

        if (Str::contains($status, 'sign')) {
            return 'signed';
        }

        return $status;
    }
}
