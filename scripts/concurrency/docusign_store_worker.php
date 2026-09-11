<?php

use App\Http\Controladores\ReservaControlador;
use App\Modelos\Usuario;
use App\Servicios\Contratos\ContratoPdfServicio;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Scripts\Concurrency\FakeContratoPdfServicio;
use Scripts\Concurrency\FakeDocuSignServicio;

/**
 * Worker process for the BLOQUE 10 real concurrency harness.
 *
 * Runs as its own independent OS process (spawned via proc_open by
 * docusign_concurrency_test.php). Never calls the real DocuSign API — uses
 * FakeDocuSignServicio, whose envelope-creation counter is shared across
 * processes via a flock-protected file.
 *
 * Args: --reservation-id=ID --client-id=ID --counter-file=PATH --result-file=PATH
 */
$app = require __DIR__.'/bootstrap.php';
require __DIR__.'/FakeDocuSignServicio.php';
require __DIR__.'/FakeContratoPdfServicio.php';

$options = getopt('', ['reservation-id:', 'client-id:', 'counter-file:', 'result-file:']);
$reservationId = (int) ($options['reservation-id'] ?? 0);
$clientId = (int) ($options['client-id'] ?? 0);
$counterFile = (string) ($options['counter-file'] ?? '');
$resultFile = (string) ($options['result-file'] ?? '');

if ($reservationId <= 0 || $clientId <= 0 || $counterFile === '' || $resultFile === '') {
    fwrite(STDERR, "Missing required arguments.\n");
    exit(1);
}

$app->instance(\App\Servicios\Contratos\DocuSignServicio::class, new FakeDocuSignServicio($counterFile));
$app->instance(ContratoPdfServicio::class, new FakeContratoPdfServicio());

try {
    $client = Usuario::findOrFail($clientId);

    $request = Request::create('/api/v1/cliente/reservas/'.$reservationId.'/contrato/docusign', 'POST', []);
    $request->setUserResolver(fn () => $client);

    $response = $app->make(ReservaControlador::class)->startEmbeddedSigning(
        $request,
        $reservationId,
        $app->make(\App\Servicios\Contratos\DocuSignServicio::class),
        $app->make(ContratoPdfServicio::class),
    );

    file_put_contents($resultFile, json_encode([
        'pid' => getmypid(),
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true),
    ]));
} catch (HttpResponseException $exception) {
    $response = $exception->getResponse();
    file_put_contents($resultFile, json_encode([
        'pid' => getmypid(),
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true),
        'controlled_rejection' => true,
    ]));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'pid' => getmypid(),
        'unhandled_exception' => get_class($exception),
        'message' => $exception->getMessage(),
    ]));
}
