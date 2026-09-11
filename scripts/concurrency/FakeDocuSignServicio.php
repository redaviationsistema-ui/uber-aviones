<?php

namespace Scripts\Concurrency;

use App\Servicios\Contratos\DocuSignServicio;

/**
 * Real fake for the BLOQUE 10 DocuSign concurrency harness.
 *
 * Extends the concrete DocuSignServicio (required by the controller's strict
 * type hint) and never calls the real DocuSign API. Every invocation of
 * crearEnvelopeParaFirmaEmbebida() increments a counter in a shared file
 * (protected with flock) so the count is accurate across independent OS
 * processes, not just within one PHP process.
 */
class FakeDocuSignServicio extends DocuSignServicio
{
    public function __construct(private readonly string $counterFile)
    {
    }

    public function estaConfigurado(): bool
    {
        return true;
    }

    public function configurationDiagnostics(): array
    {
        return ['ok' => true, 'checks' => [], 'missing' => []];
    }

    public function runtimeDiagnosticsFromException(\Throwable $exception): array
    {
        return [];
    }

    public function crearEnvelopeParaFirmaEmbebida(
        string $pdfAbsolutePath,
        string $signerName,
        string $signerEmail,
        string $clientUserId
    ): string {
        $handle = fopen($this->counterFile, 'c+');
        flock($handle, LOCK_EX);
        $count = (int) fread($handle, 64) + 1;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) $count);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        usleep(100000);

        return 'env-concurrency-'.$count.'-'.getmypid();
    }

    public function crearRecipientView(
        string $envelopeId,
        string $signerName,
        string $signerEmail,
        string $clientUserId,
        string $returnUrl
    ): string {
        return 'https://example.test/sign/'.$envelopeId;
    }

    public function construirReturnUrl(int $contractId, ?string $returnPath = null, array $query = []): string
    {
        return 'https://example.test/return/'.$contractId;
    }
}
