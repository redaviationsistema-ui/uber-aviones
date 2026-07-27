<?php

namespace Tests\Feature;

use App\Modelos\IdempotencyKey;
use App\Modelos\Reserva;
use App\Modelos\Usuario;
use App\Servicios\Reservas\CommercialSnapshotService;
use App\Servicios\Reservas\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

class ReservationHardeningServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_idempotency_key_and_payload_replays_without_running_twice(): void
    {
        $this->seed();
        $user = Usuario::factory()->create();
        $request = $this->requestFor($user, 'reservation:101');
        $executions = 0;
        $service = app(IdempotencyService::class);

        $first = $service->replayOrRun($request, 'reservation.create', 'fallback', ['request_id' => 101], function () use (&$executions) {
            $executions++;

            return response()->json(['success' => true, 'reservation_id' => 501], 201);
        });
        $second = $service->replayOrRun($request, 'reservation.create', 'fallback', ['request_id' => 101], function () use (&$executions) {
            $executions++;

            return response()->json(['success' => true, 'reservation_id' => 999], 201);
        });

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, $executions);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_same_idempotency_key_with_different_payload_is_rejected(): void
    {
        $this->seed();
        $user = Usuario::factory()->create();
        $request = $this->requestFor($user, 'reservation:102');
        $service = app(IdempotencyService::class);

        $service->replayOrRun(
            $request,
            'reservation.create',
            'fallback',
            ['request_id' => 102],
            fn () => response()->json(['success' => true], 201),
        );

        try {
            $service->replayOrRun(
                $request,
                'reservation.create',
                'fallback',
                ['request_id' => 103],
                fn () => response()->json(['success' => true], 201),
            );
            $this->fail('La reutilización de clave con otro payload debió fallar.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame(
                'IDEMPOTENCY_KEY_REUSED',
                json_decode((string) $exception->getResponse()->getContent(), true)['code'],
            );
        }
    }

    public function test_failed_idempotent_transaction_does_not_leave_completed_key(): void
    {
        $this->seed();
        $user = Usuario::factory()->create();
        $request = $this->requestFor($user, 'reservation:failure');

        try {
            app(IdempotencyService::class)->replayOrRun(
                $request,
                'reservation.create',
                'fallback',
                ['request_id' => 104],
                fn () => throw new RuntimeException('forced rollback'),
            );
            $this->fail('La operación debía fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced rollback', $exception->getMessage());
        }

        $this->assertSame(0, IdempotencyKey::query()->count());
    }

    public function test_commercial_snapshot_has_sha256_and_detects_mutation(): void
    {
        $this->seed();
        $service = app(CommercialSnapshotService::class);
        $reservation = new Reserva([
            'client_id' => 10,
            'provider_id' => 20,
            'aircraft_id' => 30,
            'total_amount' => 15000,
            'currency' => 'USD',
        ]);
        $snapshot = $service->build($reservation);
        $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $reservation->forceFill([
            'commercial_snapshot' => $snapshot,
            'commercial_snapshot_hash' => $hash,
        ]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $reservation->commercial_snapshot_hash);
        $service->assertIntegrity($reservation);

        $snapshot['total_amount'] = '1.00';
        $reservation->forceFill(['commercial_snapshot' => $snapshot]);

        $this->expectException(RuntimeException::class);
        $service->assertIntegrity($reservation);
    }

    private function requestFor(Usuario $user, string $key): Request
    {
        $request = Request::create('/test-idempotency', 'POST');
        $request->headers->set('Idempotency-Key', $key);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
