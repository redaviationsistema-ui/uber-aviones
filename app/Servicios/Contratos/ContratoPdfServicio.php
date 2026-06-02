<?php

namespace App\Servicios\Contratos;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class ContratoPdfServicio
{
    public function guardarContratoReserva(string $contractCode, int $reservationId, array $payload): string
    {
        $relativePath = sprintf(
            'contracts/reservations/%d/%s_%s.pdf',
            $reservationId,
            strtolower($contractCode),
            now()->format('YmdHis')
        );

        $pdf = Pdf::loadView('pdf.contract', $payload)->setPaper('a4');

        Storage::disk('local')->put($relativePath, $pdf->output());

        return $relativePath;
    }

    public function guardarContratoFirmadoManual(string $contractCode, int $reservationId, array $payload): string
    {
        $relativePath = sprintf(
            'contracts/reservations/%d/signed_manual_%s_%s.pdf',
            $reservationId,
            strtolower($contractCode),
            now()->format('YmdHis')
        );

        $pdf = Pdf::loadView('pdf.contract', $payload)->setPaper('a4');

        Storage::disk('local')->put($relativePath, $pdf->output());

        return $relativePath;
    }

    public function guardarPdfFirmado(int $reservationId, string $envelopeId, string $pdfContent): string
    {
        $relativePath = sprintf(
            'contracts/reservations/%d/signed_%s_%s.pdf',
            $reservationId,
            $envelopeId,
            now()->format('YmdHis')
        );

        Storage::disk('local')->put($relativePath, $pdfContent);

        return $relativePath;
    }

    public function rutaAbsoluta(string $relativePath): string
    {
        return Storage::disk('local')->path($relativePath);
    }
}
