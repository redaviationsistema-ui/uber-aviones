<?php

namespace App\Servicios\Contratos;

use App\Modelos\ContratoReserva;
use App\Modelos\Pago;
use App\Modelos\Reserva;
use App\Modelos\Usuario;
use Illuminate\Support\Str;

class ContratoReservaServicio
{
    public function registrarFirma(
        Reserva $reservation,
        ContratoReserva $contract,
        ?Usuario $actor = null,
        array $termsSnapshot = [],
        ?string $signedPdfPath = null,
        ?string $docusignStatus = null,
    ): Pago {
        $normalizedDocusignStatus = strtolower(trim((string) ($docusignStatus ?? '')));
        $contractStatus = $normalizedDocusignStatus === 'completed' ? 'completed' : 'signed';
        $signedAt = now();
        $completedAt = $normalizedDocusignStatus === 'completed' ? now() : $contract->completed_at;

        $contract->update([
            'status' => $contractStatus,
            'signed_by_user_id' => $actor?->id,
            'signed_at' => $signedAt,
            'completed_at' => $completedAt,
            'document_url' => $signedPdfPath ?? $contract->document_url,
            'signed_pdf_path' => $signedPdfPath ?? $contract->signed_pdf_path,
            'docusign_status' => $docusignStatus ?? $contract->docusign_status,
            'terms_snapshot' => $termsSnapshot ?: $contract->terms_snapshot,
        ]);

        $paymentOrder = $reservation->payments()
            ->whereIn('status', ['pending', 'failed'])
            ->latest('id')
            ->first();

        if (! $paymentOrder) {
            $paymentOrder = $reservation->payments()->create([
                'user_id' => $reservation->client_id,
                'flight_request_id' => $reservation->flight_request_id,
                'payment_type' => 'reservation',
                'amount' => $reservation->total_amount,
                'currency' => $reservation->currency ?? 'USD',
                'provider' => 'manual',
                'status' => 'pending',
                'transaction_reference' => 'PAY-'.Str::upper(Str::random(10)),
            ]);
        }

        if ($reservation->flight_request_id) {
            $reservation->flightRequest()->update([
                'status' => 'reserved',
                'workflow_status' => 'pago pendiente',
                'payment_status' => 'pending',
            ]);
        }

        $reservation->update([
            'status' => 'pending_payment',
        ]);

        return $paymentOrder;
    }
}
