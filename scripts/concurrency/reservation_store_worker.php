<?php

use App\Http\Controladores\ReservaControlador;
use App\Modelos\Usuario;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Worker process for the BLOQUE 9 real concurrency harness.
 *
 * Runs as its own independent OS process (spawned via proc_open by
 * reservation_concurrency_test.php), with its own PHP interpreter and its own
 * PostgreSQL connection from the very first line. Performs exactly one
 * ReservaControlador::store() call and writes the outcome to --result-file.
 *
 * Args: --flight-request-id=ID --client-id=ID --result-file=PATH
 */
$app = require __DIR__.'/bootstrap.php';

$options = getopt('', ['flight-request-id:', 'client-id:', 'result-file:']);
$flightRequestId = (int) ($options['flight-request-id'] ?? 0);
$clientId = (int) ($options['client-id'] ?? 0);
$resultFile = (string) ($options['result-file'] ?? '');

if ($flightRequestId <= 0 || $clientId <= 0 || $resultFile === '') {
    fwrite(STDERR, "Missing required arguments.\n");
    exit(1);
}

try {
    $client = Usuario::findOrFail($clientId);

    $request = Request::create('/api/v1/cliente/reservas', 'POST', [
        'flight_request_id' => $flightRequestId,
    ]);
    $request->setUserResolver(fn () => $client);

    $response = $app->make(ReservaControlador::class)->store($request);

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
