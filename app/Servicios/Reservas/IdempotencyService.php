<?php

namespace App\Servicios\Reservas;

use App\Modelos\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    public function key(Request $request, string $fallback): string
    {
        return trim((string) $request->header('Idempotency-Key', $fallback));
    }

    public function replayOrRun(
        Request $request,
        string $operation,
        string $fallbackKey,
        array $payload,
        callable $callback,
    ): JsonResponse {
        $key = $this->key($request, $fallbackKey);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        try {
            return DB::transaction(function () use ($request, $operation, $key, $hash, $callback) {
                $record = IdempotencyKey::query()
                    ->where('user_id', $request->user()->id)
                    ->where('operation', $operation)
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($record) {
                    if (! hash_equals((string) $record->request_hash, $hash)) {
                        throw new HttpResponseException(response()->json([
                            'success' => false,
                            'code' => 'IDEMPOTENCY_KEY_REUSED',
                            'message' => 'La clave de idempotencia ya fue utilizada con otros datos.',
                        ], 409));
                    }
                    if ($record->completed_at) {
                        return response()->json($record->response_body ?? [], (int) $record->response_status);
                    }
                    throw new HttpResponseException(response()->json([
                        'success' => false,
                        'code' => 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                        'message' => 'La operación con esta clave sigue en proceso.',
                    ], 409));
                }

                $record = IdempotencyKey::create([
                    'user_id' => $request->user()->id,
                    'operation' => $operation,
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                ]);

                $response = $callback($key);
                if (! $response instanceof JsonResponse) {
                    $response = response()->json($response);
                }

                $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $record->update([
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $body,
                    'completed_at' => now(),
                ]);

                return $response;
            }, 3);
        } catch (QueryException $exception) {
            $record = IdempotencyKey::query()
                ->where('user_id', $request->user()->id)
                ->where('operation', $operation)
                ->where('idempotency_key', $key)
                ->first();

            if (! $record) {
                throw $exception;
            }
            if (! hash_equals((string) $record->request_hash, $hash)) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'IDEMPOTENCY_KEY_REUSED',
                    'message' => 'La clave de idempotencia ya fue utilizada con otros datos.',
                ], 409));
            }
            if ($record->completed_at) {
                return response()->json($record->response_body ?? [], (int) $record->response_status);
            }

            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                'message' => 'La operación con esta clave sigue en proceso.',
            ], 409));
        }
    }
}
