<?php

namespace Scripts\Concurrency;

use App\Servicios\Contratos\ContratoPdfServicio;

/**
 * Real fake for the BLOQUE 10 DocuSign concurrency harness.
 *
 * Avoids real PDF rendering (Dompdf) in the concurrency harness; returns a
 * deterministic non-existent path, which is safe because the controller only
 * treats a missing file as "no signature anchor detected", not an error,
 * when the HTML-based contract path is not in use (current production code).
 */
class FakeContratoPdfServicio extends ContratoPdfServicio
{
    public function guardarContratoReserva(string $contractCode, int $reservationId, array $payload): string
    {
        return 'contracts/reservations/'.$reservationId.'/fake-concurrency.pdf';
    }

    public function guardarContratoReservaDesdeHtml(string $contractCode, int $reservationId, string $html): string
    {
        return 'contracts/reservations/'.$reservationId.'/fake-concurrency.pdf';
    }

    public function rutaAbsoluta(string $relativePath): string
    {
        return '/tmp/uberaviones-nonexistent-fake-concurrency.pdf';
    }
}
