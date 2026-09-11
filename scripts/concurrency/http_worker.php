<?php
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Http\Request;
use App\Servicios\Contratos\{DocuSignServicio, ContratoPdfServicio};
$app = require __DIR__.'/bootstrap.php';
require __DIR__.'/FakeDocuSignServicio.php';
require __DIR__.'/FakeContratoPdfServicio.php';
[$script, $mode, $id, $token, $file, $counter, $key, $scenario] = $argv;
$app->instance(DocuSignServicio::class, new Scripts\Concurrency\FakeDocuSignServicio($counter));
$app->instance(ContratoPdfServicio::class, new Scripts\Concurrency\FakeContratoPdfServicio());
$events = [];
foreach (['App\\Events\\*', 'App\\Eventos\\*'] as $pattern) Event::listen($pattern, function ($name) use (&$events) { $events[] = $name; });
$pgPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
file_put_contents($file.'.ready', (string) $pgPid);
$path = $mode === 'reservation' ? '/api/v1/cliente/reservas' : '/api/v1/cliente/reservas/'.$id.'/contrato/docusign';
$body = $mode === 'reservation' ? ['flight_request_id' => (int) $id] : ['regenerate' => $scenario === 'regenerate'];
$request = Request::create($path, 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
], json_encode($body));
if ($key !== '') $request->headers->set('Idempotency-Key', $key);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$data = json_decode($response->getContent(), true);
file_put_contents($file.'.json', json_encode([
    'pid' => getmypid(), 'postgres_pid' => $pgPid, 'status' => $response->getStatusCode(),
    'reservation_id' => $data['reservation']['id'] ?? null, 'envelope_id' => $data['envelope_id'] ?? null,
    'domain_events' => $events, 'error' => $response->getStatusCode() >= 400 ? $data : null,
]));
