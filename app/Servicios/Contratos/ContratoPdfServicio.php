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

    public function guardarContratoReservaDesdeHtml(string $contractCode, int $reservationId, string $html): string
    {
        $relativePath = sprintf(
            'contracts/reservations/%d/%s_%s.pdf',
            $reservationId,
            strtolower($contractCode),
            now()->format('YmdHis')
        );

        $pdf = Pdf::setOption([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'dpi' => 96,
        ])->loadHTML($this->normalizeHtmlDocument($html))->setPaper('a4');

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

    private function normalizeHtmlDocument(string $html): string
    {
        $normalized = trim($html);

        if ($normalized === '') {
            return '<html><body></body></html>';
        }

        if (! str_contains(strtolower($normalized), '<html')) {
            $normalized = '<html><body>'.$normalized.'</body></html>';
        }

        $normalized = $this->inlineKnownPublicAssets($normalized);

        if (! str_contains($normalized, '/sig_cliente/')) {
            $normalized = preg_replace(
                '/<\/body>/i',
                '<span style="color: transparent; font-size: 1px; line-height: 1px;">/sig_cliente/</span></body>',
                $normalized,
                1,
            ) ?: $normalized.'<span style="color: transparent; font-size: 1px; line-height: 1px;">/sig_cliente/</span>';
        }

        return $normalized;
    }

    private function inlineKnownPublicAssets(string $html): string
    {
        $assets = [
            'MARGEN/image.png' => public_path('MARGEN/image.png'),
            'logo.png' => public_path('logo.png'),
        ];

        foreach ($assets as $needle => $absolutePath) {
            $dataUri = $this->fileToDataUri($absolutePath);
            if (! $dataUri) {
                continue;
            }

            $pattern = '/(<img[^>]+src=["\'])([^"\']*'.preg_quote($needle, '/').'[^"\']*)(["\'][^>]*>)/i';
            $html = preg_replace($pattern, '$1'.$dataUri.'$3', $html) ?: $html;
        }

        return $html;
    }

    private function fileToDataUri(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false || $contents === '') {
            return null;
        }

        $mimeType = mime_content_type($absolutePath) ?: 'image/png';

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }
}
