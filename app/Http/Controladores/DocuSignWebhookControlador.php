<?php

namespace App\Http\Controladores;

use App\Modelos\ContratoReserva;
use App\Servicios\Contratos\ContratoPdfServicio;
use App\Servicios\Contratos\ContratoReservaServicio;
use App\Servicios\Contratos\DocuSignServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $secret = trim((string) config('services.docusign.webhook_secret'));
        $signature = trim((string) $request->header('X-DocuSign-Signature-1'));
        $expectedSignature = $secret !== ''
            ? base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true))
            : '';
        if ($secret === '' || $signature === '' || ! hash_equals($expectedSignature, $signature)) {
            Log::warning('Webhook DocuSign rechazado por firma HMAC inválida.', [
                'secret_configured' => $secret !== '',
                'signature_present' => $signature !== '',
            ]);

            return response()->json([
                'success' => false,
                'code' => 'INVALID_WEBHOOK_SIGNATURE',
                'message' => 'Webhook de DocuSign inválido.',
            ], 401);
        }

        $payload = $request->all();
        $contract = ContratoReserva::query()
            ->where('docusign_envelope_id', $this->extractEnvelopeId($payload))
            ->with(['reservation.client', 'reservation.flightRequest', 'reservation.payments'])
            ->first();

        if (! $contract) {
            return response()->json(['success' => true, 'received' => true]);
        }

        $docusignStatus = $this->extractStatus($payload);
        $currentStatus = strtolower(trim((string) $contract->docusign_status));
        if ($this->isStatusRegression($currentStatus, $docusignStatus)) {
            $contract->update(['last_webhook_payload' => $payload]);

            return response()->json(['success' => true, 'received' => true]);
        }

        if ($docusignStatus !== 'completed') {
            $contract->update([
                'docusign_status' => $docusignStatus ?: $contract->docusign_status,
                'last_webhook_payload' => $payload,
            ]);

            return response()->json(['success' => true, 'received' => true]);
        }

        if ($contract->completed_at || $currentStatus === 'completed') {
            $contract->update(['last_webhook_payload' => $payload]);

            return response()->json([
                'success' => true,
                'received' => true,
                'duplicate' => true,
                'contract_id' => $contract->id,
            ]);
        }

        try {
            $signedPdf = $docuSignServicio->descargarPdfCombinado((string) $contract->docusign_envelope_id);
            $signedPdfPath = $contratoPdfServicio->guardarPdfFirmado(
                (int) $contract->reservation_id,
                (string) $contract->docusign_envelope_id,
                $signedPdf
            );

            $paymentOrder = DB::transaction(function () use ($contract, $payload, $docusignStatus, $signedPdfPath, $contratoReservaServicio) {
                $lockedContract = ContratoReserva::query()
                    ->with(['reservation.client', 'reservation.flightRequest', 'reservation.payments'])
                    ->lockForUpdate()
                    ->findOrFail($contract->id);

                if ($lockedContract->completed_at || strtolower((string) $lockedContract->docusign_status) === 'completed') {
                    $lockedContract->update(['last_webhook_payload' => $payload]);

                    return null;
                }

                $termsSnapshot = is_array($lockedContract->terms_snapshot) ? $lockedContract->terms_snapshot : [];
                $termsSnapshot['docusign'] = [
                    'completed_via' => 'webhook',
                    'status' => $docusignStatus,
                    'completed_at' => now()->toIso8601String(),
                ];

                $lockedContract->update(['last_webhook_payload' => $payload]);

                return $contratoReservaServicio->registrarFirma(
                    $lockedContract->reservation,
                    $lockedContract,
                    $lockedContract->reservation->client,
                    $termsSnapshot,
                    $signedPdfPath,
                    $docusignStatus
                );
            });

            if (! $paymentOrder) {
                return response()->json([
                    'success' => true,
                    'received' => true,
                    'duplicate' => true,
                    'contract_id' => $contract->id,
                ]);
            }

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

    private function isStatusRegression(string $currentStatus, string $incomingStatus): bool
    {
        $rank = [
            'created' => 1,
            'sent' => 2,
            'delivered' => 3,
            'signed' => 4,
            'completed' => 5,
        ];

        return isset($rank[$currentStatus], $rank[$incomingStatus])
            && $rank[$incomingStatus] < $rank[$currentStatus];
    }
}
