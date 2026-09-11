<?php
use App\Modelos\{Aeronave, ContratoReserva, Proveedor, Reserva, SolicitudVuelo, TokenApi, Usuario};
use Illuminate\Support\Facades\DB;
require __DIR__.'/bootstrap.php';
$mode = $argv[1] ?? 'reservation';
$scenario = $argv[2] ?? 'default';
if (! in_array($mode, ['reservation', 'docusign'], true)) throw new RuntimeException('Invalid mode');
$resultDir = sys_get_temp_dir().'/phase1_'.bin2hex(random_bytes(6));
mkdir($resultDir);
file_put_contents($resultDir.'/counter', '0');
$client = Usuario::factory()->create(['role' => Usuario::ROLE_CLIENT, 'status' => 'active']);
$providerUser = Usuario::factory()->create(['role' => Usuario::ROLE_PROVIDER, 'status' => 'active']);
$provider = Proveedor::query()->create([
    'user_id' => $providerUser->id,
    'company_name' => 'Concurrency Provider',
    'commercial_name' => 'Concurrency Provider',
    'approval_status' => 'approved',
]);
$aircraft = Aeronave::query()->create([
    'provider_id' => $provider->id,
    'model' => 'Citation Concurrency',
    'registration' => 'XA-'.strtoupper(substr(uniqid(), -7)),
    'capacity' => 6,
    'base_airport' => 'MMMX',
    'range_km' => 2500,
    'speed_kmh' => 700,
    'hourly_rate' => 5000,
    'status' => 'active',
    'currency' => 'USD',
]);
$flightRequest = SolicitudVuelo::query()->create([
    'client_id' => $client->id,
    'origin' => 'MMMX',
    'destination' => 'MMTO',
    'departure_datetime' => now()->addDays(5),
    'passengers' => 2,
    'trip_type' => 'one_way',
    'assigned_provider_id' => $provider->id,
    'assigned_aircraft_id' => $aircraft->id,
    'final_price' => 15000,
    'currency' => 'USD',
    'status' => 'reserved',
]);


$token = TokenApi::issue($client, 'concurrency-test');
$reservation = null;
if ($mode === 'docusign') {
    $reservation = Reserva::create([
        'client_id' => $client->id, 'provider_id' => $provider->id, 'aircraft_id' => $aircraft->id,
        'flight_request_id' => $flightRequest->id, 'reservation_code' => 'CONC-'.$flightRequest->id,
        'status' => 'pending_payment', 'total_amount' => 15000, 'currency' => 'USD',
    ]);
    if ($scenario !== 'missing-contract') {
        ContratoReserva::create([
            'reservation_id' => $reservation->id, 'contract_code' => 'CTR-CONC-'.$flightRequest->id,
            'status' => 'generated', 'signer_name' => $client->name, 'signer_email' => $client->email,
            'docusign_status' => $scenario === 'regenerate' ? 'expired' : 'draft',
            'docusign_envelope_id' => $scenario === 'regenerate' ? 'old-'.$flightRequest->id : null,
        ]);
    }
}
function counts(): array {
    $counts = [];
    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $row) {
        $counts[$row->tablename] = DB::table($row->tablename)->count();
    }
    return $counts;
}
$before = counts();
$processes = [];
function startWorker(string $label, string $key = ''): array {
    global $resultDir, $mode, $scenario, $token, $flightRequest, $reservation;
    $file = $resultDir.'/'.$label;
    $command = [PHP_BINARY, __DIR__.'/http_worker.php', $mode, (string) ($reservation?->id ?? $flightRequest->id), $token, $file, $resultDir.'/counter', $key, $scenario];
    $handle = proc_open($command, [1 => ['file', $file.'.stdout', 'w'], 2 => ['file', $file.'.stderr', 'w']], $pipes);
    if (! is_resource($handle)) throw new RuntimeException('Unable to spawn worker');
    return ['handle' => $handle, 'file' => $file];
}
function finishWorker(array $process): array {
    $exit = proc_close($process['handle']);
    $result = is_file($process['file'].'.json') ? json_decode(file_get_contents($process['file'].'.json'), true) : null;
    if ($exit !== 0 || ! is_array($result)) throw new RuntimeException('Worker failed; inspect '.$process['file'].'.stderr');
    return $result;
}
// The coordinator holds the exact row both requests must lock. It releases
// only after PostgreSQL proves TWO independent sessions are waiting on locks.
DB::beginTransaction();
DB::table($mode === 'reservation' ? 'flight_requests' : 'reservations')
    ->where('id', $reservation?->id ?? $flightRequest->id)->lockForUpdate()->first();
$overlap = [];
try {
    foreach (['a', 'b'] as $label) $processes[] = startWorker($label, $scenario === 'different-keys' ? 'request-'.$label : '');
    $deadline = microtime(true) + 12;
    do {
        $pids = [];
        foreach ($processes as $p) if (is_file($p['file'].'.ready')) $pids[] = (int) file_get_contents($p['file'].'.ready');
        if (count($pids) === 2) {
            $overlap = DB::select("SELECT pid, wait_event_type, wait_event, pg_blocking_pids(pid) AS blocking_pids FROM pg_stat_activity WHERE pid IN (?, ?) AND wait_event_type = 'Lock'", $pids);
            if (count($overlap) === 2) break;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
} finally {
    DB::rollBack();
}
$results = array_map('finishWorker', $processes);
$after = counts();
$deltas = [];
foreach ($after as $table => $count) $deltas[$table] = $count - $before[$table];
$retry = finishWorker(startWorker('retry'));
$afterRetry = counts();
$expected = $mode === 'reservation'
    ? ['reservations' => 1, 'reservation_contracts' => 1, 'commissions' => 1, 'aircraft_availability_blocks' => 1, 'audit_logs' => 1, 'idempotency_keys' => $scenario === 'different-keys' ? 2 : 1]
    : ['audit_logs' => 1, 'aircraft_availability_blocks' => $scenario === 'missing-contract' ? 1 : 0, 'reservation_contracts' => $scenario === 'missing-contract' ? 1 : 0];
$failures = [];
if (count($overlap) !== 2) $failures[] = 'Did not observe both DB sessions waiting on locks';
foreach ($deltas as $table => $delta) if ($delta !== ($expected[$table] ?? 0)) $failures[] = "Unexpected side effect $table: $delta";
// New retry key is allowed, but no new business record or event is allowed.
foreach ($afterRetry as $table => $count) if ($table !== 'idempotency_keys' && $count !== $after[$table]) $failures[] = 'Retry duplicated '.$table;
$canonical = Reserva::where('flight_request_id', $flightRequest->id)->first();
if (Reserva::where('flight_request_id', $flightRequest->id)->count() !== 1) $failures[] = 'Reservation count';
foreach ([...$results, $retry] as $result) {
    if (! in_array($result['status'] ?? 0, [200, 201], true)) $failures[] = 'Unexpected HTTP status';
    if (($result['reservation_id'] ?? null) !== $canonical?->id) $failures[] = 'Non-canonical reservation';
    if (($result['domain_events'] ?? []) !== []) $failures[] = 'Unexpected domain event';
}
$envelopes = (int) file_get_contents($resultDir.'/counter');
if ($mode === 'docusign') {
    if ($envelopes !== 1) $failures[] = 'Envelope creation count';
    foreach ([...$results, $retry] as $result) if (($result['envelope_id'] ?? null) !== $canonical?->contract?->docusign_envelope_id) $failures[] = 'Envelope mismatch';
}
echo json_encode([
    'environment' => app()->environment(), 'database' => DB::getDatabaseName(), 'mode' => $mode, 'scenario' => $scenario,
    'postgres_lock_overlap' => $overlap, 'responses' => $results, 'retry' => $retry,
    'reservation_count' => Reserva::where('flight_request_id', $flightRequest->id)->count(),
    'envelope_creation_calls' => $envelopes, 'table_deltas' => $deltas, 'failures' => $failures,
    'artifacts' => $resultDir,
], JSON_PRETTY_PRINT), PHP_EOL;
exit($failures === [] ? 0 : 1);
