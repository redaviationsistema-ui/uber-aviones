<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\Cotizacion;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class AircraftAvailabilityService
{
    public function __construct(
        private readonly AircraftHoldDateResolver $aircraftHoldDateResolver,
    ) {
    }

    private const RESERVABLE_AIRCRAFT_STATUSES = [
        'active',
        'trial_active',
        'approved',
        'aprobado',
        'aprobada',
        'available',
        'disponible',
    ];

    public const STATUS_HELD = 'held';
    public const STATUS_BOOKED = 'booked';
    public const STATUS_ACTIVE_LEGACY = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RELEASED = 'released';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CONTRACT_PENDING = 'contract_pending';

    private static ?array $availabilityBlockColumns = null;

    private function availabilityBlockSupports(string $column): bool
    {
        if (self::$availabilityBlockColumns === null) {
            self::$availabilityBlockColumns = Schema::getColumnListing('aircraft_availability_blocks');
        }

        return in_array($column, self::$availabilityBlockColumns, true);
    }

    public function blockAircraftForPaidReservation(Reserva $reservation): AircraftAvailabilityBlock
    {
        $reservation->loadMissing(['flightRequest.legs', 'legs', 'aircraft']);

        $aircraftId = (int) ($reservation->aircraft_id ?: $reservation->flightRequest?->assigned_aircraft_id);
        if ($aircraftId <= 0) {
            throw new RuntimeException('La reserva no tiene una aeronave asignada para bloquear disponibilidad.');
        }

        [$start, $end] = $this->resolvePaidReservationWindow($reservation, $aircraftId);
        $logContext = $this->buildPaidReservationBlockLogContext($reservation, $aircraftId, $start, $end);

        Log::info('aircraft_paid_reservation_block_started', $logContext);
        $this->ensureAircraftAvailable(
            aircraftId: $aircraftId,
            requestedStart: $start,
            requestedEnd: $end,
            ignoreReservationId: $reservation->id,
            ignoreBlockId: null,
            ignoreQuoteId: $reservation->quote_id,
        );

        $attributes = [
            'quote_id' => $reservation->quote_id,
            'flight_request_id' => $reservation->flight_request_id,
            'user_id' => $reservation->client_id,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'status' => self::STATUS_BOOKED,
            'payment_status' => 'paid',
            'source' => 'reservation_payment_confirmed',
            'reason' => 'Reserva pagada y aeronave apartada para el horario confirmado.',
            'notes' => 'Bloqueo confirmado por pago exitoso de reserva.',
            'hold_expires_at' => null,
        ];

        if ($this->availabilityBlockSupports('block_type')) {
            $attributes['block_type'] = 'confirmed_flight';
        }

        if ($this->availabilityBlockSupports('released_at')) {
            $attributes['released_at'] = null;
        }

        $block = AircraftAvailabilityBlock::query()->updateOrCreate(
            [
                'aircraft_id' => $aircraftId,
                'reservation_id' => $reservation->id,
            ],
            $attributes,
        );

        $persistedBlock = AircraftAvailabilityBlock::query()->find($block->id);
        if (
            ! $persistedBlock
            || (int) $persistedBlock->aircraft_id !== $aircraftId
            || (int) $persistedBlock->reservation_id !== (int) $reservation->id
            || $persistedBlock->status !== self::STATUS_BOOKED
            || ! $persistedBlock->start_datetime
            || ! $persistedBlock->end_datetime
        ) {
            Log::error('aircraft_paid_reservation_block_failed', $logContext + [
                'booked_created' => false,
                'booked_id' => $block->id ?? null,
                'persisted' => $persistedBlock?->toArray(),
            ]);

            throw new RuntimeException('No fue posible persistir el bloqueo definitivo de la reserva pagada.');
        }

        Log::info('aircraft_booked_block_created', $logContext + [
            'booked_created' => true,
            'booked_id' => $persistedBlock->id,
            'final_status' => $persistedBlock->status,
        ]);

        $releasedRows = AircraftAvailabilityBlock::query()
            ->where('aircraft_id', $aircraftId)
            ->where(function ($query) use ($reservation) {
                $query->where('reservation_id', $reservation->id)
                    ->orWhere(function ($inner) use ($reservation) {
                        $inner->whereNull('reservation_id')
                            ->where('quote_id', $reservation->quote_id)
                            ->where('user_id', $reservation->client_id);
                    });
            })
            ->whereKeyNot($block->id)
            ->whereIn('status', [self::STATUS_HELD, self::STATUS_ACTIVE_LEGACY])
            ->update([
                'status' => self::STATUS_RELEASED,
                'reason' => 'Retencion reemplazada por bloqueo confirmado de reserva pagada.',
                'hold_expires_at' => null,
                'released_at' => $this->availabilityBlockSupports('released_at') ? now() : null,
            ]);

        Log::info('aircraft_previous_hold_released', $logContext + [
            'hold_found' => $releasedRows > 0,
            'rows_affected' => $releasedRows,
            'booked_id' => $persistedBlock->id,
        ]);

        $staleBlockReleasePayload = [
            'status' => self::STATUS_RELEASED,
            'reason' => 'Bloqueo anterior liberado por reprogramacion de la reserva.',
        ];

        if ($this->availabilityBlockSupports('released_at')) {
            $staleBlockReleasePayload['released_at'] = now();
        }

        AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->whereIn('status', [self::STATUS_BOOKED, self::STATUS_ACTIVE_LEGACY])
            ->whereKeyNot($block->id)
            ->update($staleBlockReleasePayload);

        return $persistedBlock->fresh();
    }

    public function releaseReservationBlock(Reserva $reservation, ?string $reasonOverride = null): ?AircraftAvailabilityBlock
    {
        $reservation->loadMissing(['flightRequest', 'latestPayment']);

        $blocks = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->whereIn('status', [self::STATUS_HELD, self::STATUS_BOOKED, self::STATUS_ACTIVE_LEGACY])
            ->get();

        if ($blocks->isEmpty()) {
            return null;
        }

        $paymentStatus = strtolower(trim((string) ($reservation->flightRequest?->payment_status ?: $reservation->latestPayment?->status ?: '')));
        $reservationStatus = strtolower(trim((string) ($reservation->status ?? '')));

        $releasedStatus = in_array($paymentStatus, ['cancelled', 'failed', 'refunded'], true)
            || in_array($reservationStatus, ['cancelled', 'canceled'], true)
            ? self::STATUS_CANCELLED
            : self::STATUS_RELEASED;

        $reason = match ($releasedStatus) {
            'cancelled' => 'Bloqueo liberado por cancelacion, fallo o reembolso de la reserva.',
            default => 'Bloqueo liberado manualmente para esta reserva.',
        };

        $releasePayload = [
            'status' => $releasedStatus,
            'reason' => $reasonOverride ?: $reason,
            'hold_expires_at' => null,
        ];

        if ($this->availabilityBlockSupports('released_at')) {
            $releasePayload['released_at'] = now();
        }

        $blocks->each->update($releasePayload);

        return $blocks->first()?->fresh();
    }

    public function lockAircraftForContractPending(
        Reserva $reservation,
        ?int $minutesToHold = null,
    ): AircraftAvailabilityBlock {
        $reservation->loadMissing(['flightRequest.legs', 'legs', 'aircraft', 'quote']);

        $aircraftId = (int) ($reservation->aircraft_id ?: $reservation->flightRequest?->assigned_aircraft_id);
        if ($aircraftId <= 0) {
            throw new RuntimeException('La reserva no tiene una aeronave asignada para apartar contrato.');
        }

        return DB::transaction(function () use ($reservation, $aircraftId, $minutesToHold) {
            $aircraft = Aeronave::query()->lockForUpdate()->findOrFail($aircraftId);
            $this->expireStaleHoldsForAircraft($aircraftId);

            if (! $this->aircraftStatusAllowsHold($aircraft->status)) {
                throw new RuntimeException('La aeronave ya no esta activa para reservarse.');
            }

            [$start, $end] = $this->resolveContractReservationWindow($reservation, $aircraft);

            $existing = AircraftAvailabilityBlock::query()
                ->where('aircraft_id', $aircraftId)
                ->where('reservation_id', $reservation->id)
                ->whereIn('status', [self::STATUS_HELD, self::STATUS_ACTIVE_LEGACY, self::STATUS_BOOKED])
                ->latest('id')
                ->first();

            if ($existing && $existing->status === self::STATUS_BOOKED) {
                return $existing;
            }

            $minutesToHold = max(
                1,
                (int) ($minutesToHold ?? config('booking.contract_hold_minutes', 720)),
            );

            $this->ensureAircraftAvailable(
                aircraftId: $aircraftId,
                requestedStart: $start,
                requestedEnd: $end,
                ignoreReservationId: $reservation->id,
                ignoreBlockId: null,
                ignoreQuoteId: $reservation->quote_id ? (int) $reservation->quote_id : null,
                ignoreHoldId: $existing?->id,
            );

            $attributes = [
                'aircraft_id' => $aircraftId,
                'reservation_id' => $reservation->id,
                'quote_id' => $reservation->quote_id,
                'flight_request_id' => $reservation->flight_request_id,
                'user_id' => $reservation->client_id,
                'start_datetime' => $start,
                'end_datetime' => $end,
                'hold_expires_at' => now()->addMinutes($minutesToHold),
                'payment_status' => self::STATUS_CONTRACT_PENDING,
                'source' => 'reservation_contract_pending',
                'status' => self::STATUS_ACTIVE_LEGACY,
                'reason' => 'Aeronave apartada para proceso contractual activo.',
                'notes' => 'Bloqueo operativo creado antes de generar o firmar contrato.',
            ];

            if ($this->availabilityBlockSupports('block_type')) {
                $attributes['block_type'] = 'contract_hold';
            }

            if ($this->availabilityBlockSupports('released_at')) {
                $attributes['released_at'] = null;
            }

            if ($existing) {
                $existing->update($attributes);

                return $existing->fresh();
            }

            return AircraftAvailabilityBlock::query()->create($attributes)->fresh();
        });
    }

    public function evaluateReservationPaymentAvailability(Reserva $reservation, bool $recoverHold = false): array
    {
        $reservation->loadMissing([
            'flightRequest.legs',
            'legs',
            'quote',
            'latestPayment',
            'contract',
        ]);

        $aircraftId = (int) ($reservation->aircraft_id ?: $reservation->flightRequest?->assigned_aircraft_id ?: 0);
        if ($aircraftId > 0) {
            $this->expireStaleHoldsForAircraft($aircraftId);
        }

        $blocks = AircraftAvailabilityBlock::query()
            ->where('aircraft_id', $aircraftId > 0 ? $aircraftId : -1)
            ->where(function ($query) use ($reservation) {
                $query->where('reservation_id', $reservation->id)
                    ->orWhere(function ($inner) use ($reservation) {
                        $inner->whereNull('reservation_id')
                            ->where('flight_request_id', $reservation->flight_request_id)
                            ->where('quote_id', $reservation->quote_id)
                            ->where('user_id', $reservation->client_id);
                    });
            })
            ->orderByDesc('id')
            ->get();

        $ownBookedBlock = $blocks->first(fn (AircraftAvailabilityBlock $block) => $this->normalizeAvailabilityStatus($block->status) === self::STATUS_BOOKED);
        $currentHold = $blocks->first(fn (AircraftAvailabilityBlock $block) => in_array(
            $this->normalizeAvailabilityStatus($block->status),
            [self::STATUS_HELD, self::STATUS_ACTIVE_LEGACY, self::STATUS_RELEASED, self::STATUS_EXPIRED, self::STATUS_CANCELLED],
            true,
        ));

        [$start, $end, $scheduleSource, $scheduleInvalidReason] = $this->resolveReservationPaymentSchedule(
            $reservation,
            $ownBookedBlock,
            $currentHold,
        );

        $normalizedHoldStatus = $this->normalizeAvailabilityStatus($currentHold?->status);
        $holdExpired = $currentHold?->hold_expires_at
            ? $currentHold->hold_expires_at->lessThanOrEqualTo(now())
            : false;
        $holdIsActive = in_array($normalizedHoldStatus, [self::STATUS_HELD, self::STATUS_ACTIVE_LEGACY], true)
            && $currentHold?->hold_expires_at
            && $currentHold->hold_expires_at->isFuture();

        $availability = [
            'available' => $aircraftId > 0 && $start && $end,
            'conflict_type' => null,
            'conflicting_block_id' => null,
        ];

        $invalidReason = null;
        $reservationBooked = false;
        $holdValid = false;

        $conflictingBlock = null;
        if ($aircraftId > 0 && $start && $end) {
            $conflictingBlock = $this->findConflictingAvailabilityBlock(
                aircraftId: $aircraftId,
                requestedStart: $start,
                requestedEnd: $end,
                ignoreReservationId: $reservation->id,
                ignoreBlockIds: array_values(array_filter([
                    $currentHold?->id,
                    $ownBookedBlock?->id,
                ])),
                ignoreQuoteId: $reservation->quote_id ? (int) $reservation->quote_id : null,
            );

            if ($conflictingBlock) {
                $availability = [
                    'available' => false,
                    'conflict_type' => $this->normalizeAvailabilityStatus($conflictingBlock->status),
                    'conflicting_block_id' => $conflictingBlock->id,
                ];
            }
        }

        if (! $start || ! $end) {
            $availability['available'] = false;
            $invalidReason = $scheduleInvalidReason ?: 'reservation_missing_schedule';
        } elseif ($conflictingBlock) {
            $invalidReason = 'aircraft_booked_by_other_reservation';
        } elseif ($ownBookedBlock && $ownBookedBlock->start_datetime && $ownBookedBlock->end_datetime) {
            $reservationBooked = true;
        } elseif (! $currentHold) {
            $invalidReason = 'hold_not_found';
        } elseif ($holdIsActive) {
            $holdValid = true;
        } elseif ($normalizedHoldStatus === self::STATUS_RELEASED || $normalizedHoldStatus === self::STATUS_CANCELLED) {
            $invalidReason = 'hold_released';
        } elseif ($normalizedHoldStatus === self::STATUS_EXPIRED || $holdExpired) {
            $invalidReason = 'hold_expired';
        } else {
            $invalidReason = 'hold_not_found';
        }

        if (
            $recoverHold
            && ! $holdValid
            && ! $reservationBooked
            && $availability['available'] === true
            && in_array($invalidReason, ['hold_expired', 'hold_released', 'hold_not_found'], true)
        ) {
            $recoveredHold = $this->recoverReservationPaymentHold($reservation, $start, $end);

            if ($recoveredHold) {
                $currentHold = $recoveredHold;
                $normalizedHoldStatus = $this->normalizeAvailabilityStatus($currentHold->status);
                $holdValid = $normalizedHoldStatus === self::STATUS_HELD
                    && $currentHold->hold_expires_at
                    && $currentHold->hold_expires_at->isFuture();
                $invalidReason = $holdValid ? null : $invalidReason;
            }
        }

        $holdPayload = $this->buildReservationPaymentAvailabilityHoldPayload(
            $currentHold,
            $reservation,
            $invalidReason,
            $holdValid,
            $ownBookedBlock,
            $start,
            $end,
        );

        $result = [
            'success' => $holdValid || $reservationBooked,
            'can_pay' => $holdValid || $reservationBooked,
            'hold_valid' => $holdValid,
            'reservation_booked' => $reservationBooked,
            'reservation_id' => $reservation->id,
            'flight_request_id' => $reservation->flight_request_id,
            'quote_id' => $reservation->quote_id,
            'aircraft_id' => $aircraftId > 0 ? $aircraftId : null,
            'schedule' => [
                'start_at' => $start?->toIso8601String(),
                'end_at' => $end?->toIso8601String(),
                'source' => $scheduleSource,
            ],
            'hold' => $holdPayload,
            'availability' => $availability,
            'invalid_reason' => $invalidReason,
            'timezone' => (string) config('app.timezone', 'UTC'),
        ];

        Log::info('reservation_payment_availability_evaluated', [
            'reservation_id' => $reservation->id,
            'flight_request_id' => $reservation->flight_request_id,
            'quote_id' => $reservation->quote_id,
            'aircraft_id' => $aircraftId > 0 ? $aircraftId : null,
            'hold_id' => $currentHold?->id,
            'block_id' => $ownBookedBlock?->id,
            'start_at' => $start?->toIso8601String(),
            'end_at' => $end?->toIso8601String(),
            'expires_at' => $currentHold?->hold_expires_at?->toIso8601String(),
            'current_time' => now()->toIso8601String(),
            'timezone' => (string) config('app.timezone', 'UTC'),
            'hold_status' => $normalizedHoldStatus,
            'block_status' => $ownBookedBlock ? $this->normalizeAvailabilityStatus($ownBookedBlock->status) : null,
            'excluded_reservation_id' => $reservation->id,
            'excluded_hold_id' => $currentHold?->id,
            'invalid_reason' => $invalidReason,
            'can_pay' => $result['can_pay'],
            'hold_valid' => $result['hold_valid'],
            'reservation_booked' => $result['reservation_booked'],
        ]);

        return $result;
    }

    private function recoverReservationPaymentHold(
        Reserva $reservation,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): ?AircraftAvailabilityBlock {
        $quote = $reservation->quote;
        $flightRequest = $reservation->flightRequest;

        if (! $quote || ! $flightRequest) {
            return null;
        }

        $payload = [
            'quote_id' => $quote->id,
            'aircraft_id' => $reservation->aircraft_id ?: $quote->aircraft_id,
            'departure_datetime' => $start?->format('Y-m-d H:i:s'),
            'start_datetime' => $start?->format('Y-m-d H:i:s'),
            'return_datetime' => $end?->format('Y-m-d H:i:s'),
            'legs' => collect($flightRequest->legs ?? [])
                ->map(fn ($leg) => [
                    'departure_datetime' => $leg->departure_datetime?->format('Y-m-d H:i:s'),
                    'arrival_datetime' => $leg->arrival_datetime?->format('Y-m-d H:i:s'),
                ])
                ->values()
                ->all(),
        ];

        try {
            return $this->holdAircraftForQuote(
                $quote->loadMissing(['flightRequest.legs', 'aircraft']),
                (int) $reservation->client_id,
                $reservation,
                null,
                $payload,
            );
        } catch (RuntimeException $exception) {
            Log::warning('reservation_payment_hold_recovery_failed', [
                'reservation_id' => $reservation->id,
                'flight_request_id' => $reservation->flight_request_id,
                'quote_id' => $reservation->quote_id,
                'aircraft_id' => $reservation->aircraft_id,
                'start_at' => $start?->toIso8601String(),
                'end_at' => $end?->toIso8601String(),
                'current_time' => now()->toIso8601String(),
                'timezone' => (string) config('app.timezone', 'UTC'),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function aircraftHasConflict(int $aircraftId, $requestedStart, $requestedEnd): bool
    {
        return $this->aircraftHasConflictExcluding($aircraftId, $requestedStart, $requestedEnd);
    }

    public function aircraftHasConflictExcluding(
        int $aircraftId,
        $requestedStart,
        $requestedEnd,
        ?int $ignoreReservationId = null,
        ?int $ignoreBlockId = null,
        ?int $ignoreQuoteId = null,
        ?int $ignoreHoldId = null,
    ): bool {
        [$start, $end] = $this->normalizeWindow($requestedStart, $requestedEnd);

        return $this->conflictQuery(
            ignoreReservationId: $ignoreReservationId,
            ignoreBlockId: $ignoreBlockId,
            ignoreQuoteId: $ignoreQuoteId,
            ignoreHoldId: $ignoreHoldId,
        )
            ->where('aircraft_id', $aircraftId)
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start)
            ->exists();
    }

    public function findConflictingAvailabilityBlock(
        int $aircraftId,
        $requestedStart,
        $requestedEnd,
        ?int $ignoreReservationId = null,
        array $ignoreBlockIds = [],
        ?int $ignoreQuoteId = null,
    ): ?AircraftAvailabilityBlock {
        [$start, $end] = $this->normalizeWindow($requestedStart, $requestedEnd);

        return $this->conflictQuery(
            ignoreReservationId: $ignoreReservationId,
            ignoreBlockId: null,
            ignoreQuoteId: $ignoreQuoteId,
            ignoreHoldId: null,
            ignoreBlockIds: $ignoreBlockIds,
        )
            ->where('aircraft_id', $aircraftId)
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start)
            ->latest('id')
            ->first();
    }

    public function buildBatchConflictContext(
        Collection $aircraftWindows,
        ?int $ignoreReservationId = null,
        ?int $ignoreBlockId = null,
        ?int $ignoreQuoteId = null,
        ?int $ignoreHoldId = null,
    ): array {
        $normalizedWindows = $aircraftWindows
            ->map(function (array $window): ?array {
                $aircraftId = (int) ($window['aircraft_id'] ?? 0);
                if ($aircraftId <= 0) {
                    return null;
                }

                [$start, $end] = $this->normalizeWindow(
                    $window['operational_window_start'] ?? null,
                    $window['operational_window_end'] ?? null,
                );

                return [
                    'aircraft_id' => $aircraftId,
                    'operational_window_start' => $start,
                    'operational_window_end' => $end,
                ];
            })
            ->filter()
            ->values();

        if ($normalizedWindows->isEmpty()) {
            return [
                'conflicts_by_aircraft' => [],
                'candidate_windows' => [],
                'query_count' => 0,
            ];
        }

        $aircraftIds = $normalizedWindows->pluck('aircraft_id')->unique()->values();
        /** @var Carbon $minimumStart */
        $minimumStart = $normalizedWindows->min('operational_window_start');
        /** @var Carbon $maximumEnd */
        $maximumEnd = $normalizedWindows->max('operational_window_end');

        $conflicts = $this->conflictQuery(
            ignoreReservationId: $ignoreReservationId,
            ignoreBlockId: $ignoreBlockId,
            ignoreQuoteId: $ignoreQuoteId,
            ignoreHoldId: $ignoreHoldId,
        )
            ->select([
                'id',
                'aircraft_id',
                'reservation_id',
                'quote_id',
                'status',
                'hold_expires_at',
                'start_datetime',
                'end_datetime',
            ])
            ->whereIn('aircraft_id', $aircraftIds->all())
            ->where('start_datetime', '<', $maximumEnd)
            ->where('end_datetime', '>', $minimumStart)
            ->orderBy('aircraft_id')
            ->orderBy('start_datetime')
            ->get()
            ->groupBy('aircraft_id')
            ->map(fn (Collection $blocks) => $blocks->values()->all())
            ->all();

        return [
            'conflicts_by_aircraft' => $conflicts,
            'candidate_windows' => $normalizedWindows
                ->map(function (array $window): array {
                    return [
                        'aircraft_id' => $window['aircraft_id'],
                        'operational_window_start' => $window['operational_window_start']->toISOString(),
                        'operational_window_end' => $window['operational_window_end']->toISOString(),
                    ];
                })
                ->values()
                ->all(),
            'query_count' => 1,
        ];
    }

    public function batchContextHasConflict(
        array $batchContext,
        int $aircraftId,
        $requestedStart,
        $requestedEnd,
    ): bool {
        [$start, $end] = $this->normalizeWindow($requestedStart, $requestedEnd);
        $blocks = $batchContext['conflicts_by_aircraft'][$aircraftId] ?? [];

        foreach ($blocks as $block) {
            $blockStart = $this->toCarbon($block['start_datetime'] ?? null);
            $blockEnd = $this->toCarbon($block['end_datetime'] ?? null);

            if (! $blockStart || ! $blockEnd) {
                continue;
            }

            if ($blockStart->lt($end) && $blockEnd->gt($start)) {
                return true;
            }
        }

        return false;
    }

    public function ensureAircraftAvailable(
        int $aircraftId,
        $requestedStart,
        $requestedEnd,
        ?int $ignoreReservationId = null,
        ?int $ignoreBlockId = null,
        ?int $ignoreQuoteId = null,
        ?int $ignoreHoldId = null,
    ): void {
        if (! $this->aircraftHasConflictExcluding($aircraftId, $requestedStart, $requestedEnd, $ignoreReservationId, $ignoreBlockId, $ignoreQuoteId, $ignoreHoldId)) {
            return;
        }

        throw new RuntimeException('Esta aeronave ya no está disponible para el horario seleccionado.');
    }

    public function excludeConflictingAircraft(Builder $query, $requestedStart, $requestedEnd): Builder
    {
        [$start, $end] = $this->normalizeWindow($requestedStart, $requestedEnd);

        return $query->whereDoesntHave('availabilityBlocks', function (Builder $builder) use ($start, $end) {
            $this->applyBlockingStatusScope($builder)
                ->where('start_datetime', '<', $end)
                ->where('end_datetime', '>', $start);
        });
    }

    public function applyAvailabilityConstraints(Builder $query, $requestedStart, $requestedEnd): Builder
    {
        [$start, $end] = $this->normalizeWindow($requestedStart, $requestedEnd);
        $blockingStatuses = [
            'occupied',
            'blocked',
            'maintenance',
            'inspection',
            'repositioning',
            'booked',
            'held',
        ];

        return $this->excludeConflictingAircraft($query, $start, $end)
            ->whereDoesntHave('availability', function (Builder $builder) use ($start, $end, $blockingStatuses) {
                $builder->whereIn(DB::raw('lower(status)'), $blockingStatuses)
                    ->where('start_datetime', '<', $end)
                    ->where('end_datetime', '>', $start);
            })
            ->where(function (Builder $builder) use ($start, $end) {
                $builder->whereDoesntHave('availability', function (Builder $inner) {
                    $inner->whereRaw('lower(status) = ?', ['available']);
                })->orWhereHas('availability', function (Builder $inner) use ($start, $end) {
                    $inner->whereRaw('lower(status) = ?', ['available'])
                        ->where('start_datetime', '<', $end)
                        ->where('end_datetime', '>', $start);
                });
            });
    }

    public function createManualBlock(Aeronave $aircraft, array $payload): AircraftAvailabilityBlock
    {
        [$start, $end] = $this->resolveWindowFromPayload([
            'departure_datetime' => $payload['start_datetime'] ?? null,
            'return_datetime' => $payload['end_datetime'] ?? null,
        ]);

        $this->ensureAircraftAvailable((int) $aircraft->id, $start, $end);

        $attributes = [
            'aircraft_id' => $aircraft->id,
            'reservation_id' => null,
            'quote_id' => null,
            'flight_request_id' => null,
            'user_id' => null,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'status' => self::STATUS_ACTIVE_LEGACY,
            'hold_expires_at' => null,
            'payment_status' => null,
            'source' => 'manual_block',
            'reason' => trim((string) ($payload['reason'] ?? 'Bloqueo manual administrativo.')),
            'notes' => trim((string) ($payload['notes'] ?? '')),
        ];

        if ($this->availabilityBlockSupports('block_type')) {
            $attributes['block_type'] = (string) ($payload['block_type'] ?? 'manual_block');
        }

        return AircraftAvailabilityBlock::query()->create($attributes);
    }

    public function releaseBlock(AircraftAvailabilityBlock $block, string $reason = 'Bloqueo liberado manualmente.'): AircraftAvailabilityBlock
    {
        $releasePayload = [
            'status' => self::STATUS_RELEASED,
            'reason' => $reason,
            'hold_expires_at' => null,
        ];

        if ($this->availabilityBlockSupports('released_at')) {
            $releasePayload['released_at'] = now();
        }

        $block->update($releasePayload);

        return $block->fresh();
    }

    public function holdAircraftForQuote(
        Cotizacion $quote,
        int $userId,
        ?Reserva $reservation = null,
        ?int $minutesToHold = null,
        array $requestData = [],
    ): AircraftAvailabilityBlock {
        $quote->loadMissing(['flightRequest.legs', 'aircraft.provider']);
        $flightRequest = $quote->flightRequest;

        if (! $flightRequest) {
            throw new RuntimeException('La cotizacion no tiene solicitud de vuelo asociada.');
        }

        if ((string) $quote->status !== 'accepted') {
            throw new RuntimeException('La cotizacion debe estar aceptada antes de iniciar el pago.');
        }

        if ($quote->expires_at?->isPast()) {
            throw new RuntimeException('La cotizacion ya vencio. Solicita una nueva antes de continuar.');
        }

        if ((int) $quote->aircraft_id <= 0) {
            throw new RuntimeException('La cotizacion no tiene aeronave asignada.');
        }

        return DB::transaction(function () use ($quote, $flightRequest, $userId, $reservation, $minutesToHold, $requestData) {
            $aircraft = Aeronave::query()->lockForUpdate()->findOrFail($quote->aircraft_id);
            $this->expireStaleHoldsForAircraft((int) $aircraft->id);

            if (! $this->aircraftStatusAllowsHold($aircraft->status)) {
                throw new RuntimeException('La aeronave ya no esta activa para reservarse.');
            }

            [$start, $end] = $this->resolveQuoteWindow($quote, $requestData);

            $existing = AircraftAvailabilityBlock::query()
                ->where('aircraft_id', $aircraft->id)
                ->where('quote_id', $quote->id)
                ->where('user_id', $userId)
                ->latest('id')
                ->first();

            if ($existing && $existing->status === self::STATUS_HELD && $existing->hold_expires_at && $existing->hold_expires_at->isFuture()) {
                $existing->setAttribute('hold_reused', true);

                return $existing;
            }

            if ($existing && $existing->status === self::STATUS_HELD && $existing->hold_expires_at && $existing->hold_expires_at->isPast()) {
                $existing->update([
                    'status' => self::STATUS_EXPIRED,
                    'reason' => 'Retencion expirada automaticamente durante un reintento de checkout.',
                    'payment_status' => 'expired',
                    'released_at' => $this->availabilityBlockSupports('released_at') ? now() : null,
                ]);
            }

            $minutesToHold = $this->normalizeHoldDuration(
                $minutesToHold ?? (int) config('booking.aircraft_hold_minutes', 15),
            );

            $this->ensureAircraftAvailable(
                aircraftId: (int) $aircraft->id,
                requestedStart: $start,
                requestedEnd: $end,
                ignoreReservationId: $reservation?->id,
                ignoreBlockId: null,
                ignoreQuoteId: null,
                ignoreHoldId: $existing?->id,
            );

            $attributes = [
                'aircraft_id' => $aircraft->id,
                'quote_id' => $quote->id,
                'flight_request_id' => $flightRequest->id,
                'user_id' => $userId,
                'reservation_id' => $reservation?->id,
                'start_datetime' => $start,
                'end_datetime' => $end,
                'hold_expires_at' => now()->addMinutes($minutesToHold),
                'payment_status' => 'pending',
                'source' => 'quote_checkout',
                'status' => self::STATUS_HELD,
                'reason' => 'Retencion temporal para completar el pago del vuelo.',
                'notes' => 'Hold automatico previo a confirmacion de pago.',
            ];

            if ($this->availabilityBlockSupports('block_type')) {
                $attributes['block_type'] = 'payment_hold';
            }

            if ($this->availabilityBlockSupports('released_at')) {
                $attributes['released_at'] = null;
            }

            $shouldReuseExistingReservationBlock =
                $reservation?->id
                && $existing
                && (int) ($existing->reservation_id ?? 0) === (int) $reservation->id;

            if ($shouldReuseExistingReservationBlock) {
                $existing->update($attributes);
                $hold = $existing->fresh();
            } else {
                $hold = AircraftAvailabilityBlock::query()->create($attributes);
            }

            $hold->setAttribute('hold_reused', false);

            return $hold;
        });
    }

    public function getActiveHoldForQuote(Cotizacion $quote, int $userId): ?AircraftAvailabilityBlock
    {
        return AircraftAvailabilityBlock::query()
            ->where('quote_id', $quote->id)
            ->where('user_id', $userId)
            ->where('status', self::STATUS_HELD)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    public function releaseQuoteHold(Cotizacion $quote, int $userId, string $reason = 'Retencion liberada manualmente.'): ?AircraftAvailabilityBlock
    {
        $hold = AircraftAvailabilityBlock::query()
            ->where('quote_id', $quote->id)
            ->where('user_id', $userId)
            ->where('status', self::STATUS_HELD)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $hold) {
            return null;
        }

        $hold->update([
            'status' => self::STATUS_RELEASED,
            'reason' => $reason,
            'hold_expires_at' => null,
            'released_at' => $this->availabilityBlockSupports('released_at') ? now() : null,
        ]);

        return $hold->fresh();
    }

    public function expireStaleHolds(): int
    {
        $count = 0;

        AircraftAvailabilityBlock::query()
            ->where('status', self::STATUS_HELD)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($blocks) use (&$count) {
                foreach ($blocks as $block) {
                    $updated = AircraftAvailabilityBlock::query()
                        ->whereKey($block->id)
                        ->where('status', self::STATUS_HELD)
                        ->whereNotNull('hold_expires_at')
                        ->where('hold_expires_at', '<=', now())
                        ->update([
                            'status' => self::STATUS_EXPIRED,
                            'reason' => 'Retencion expirada por falta de pago dentro del tiempo permitido.',
                            'payment_status' => 'expired',
                            'released_at' => $this->availabilityBlockSupports('released_at') ? now() : null,
                        ]);

                    $count += $updated;
                }
            });

        return $count;
    }

    public function resolveQuoteWindow(Cotizacion $quote, array $requestData = []): array
    {
        $payload = $this->aircraftHoldDateResolver->buildPayload($requestData, $quote);
        $resolvedStart = $this->aircraftHoldDateResolver->resolve($requestData, $quote);

        if ($resolvedStart && empty($payload['departure_datetime'])) {
            $payload['departure_datetime'] = $resolvedStart->format('Y-m-d H:i:s');
        }

        return $this->resolveWindowFromPayload($payload);
    }

    private function expireStaleHoldsForAircraft(int $aircraftId): void
    {
        AircraftAvailabilityBlock::query()
            ->where('aircraft_id', $aircraftId)
            ->where('status', self::STATUS_HELD)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->update([
                'status' => self::STATUS_EXPIRED,
                'reason' => 'Retencion expirada automaticamente durante validacion de disponibilidad.',
                'payment_status' => 'expired',
                'released_at' => $this->availabilityBlockSupports('released_at') ? now() : null,
            ]);
    }

    private function normalizeAvailabilityStatus(mixed $status): string
    {
        $normalized = strtolower(trim((string) $status));

        return match ($normalized) {
            'held', 'hold', 'active' => $normalized === 'active' ? self::STATUS_ACTIVE_LEGACY : self::STATUS_HELD,
            'booked', 'reserved', 'confirmado', 'confirmed' => self::STATUS_BOOKED,
            'released', 'liberado', 'liberada' => self::STATUS_RELEASED,
            'expired', 'expirada', 'expirado' => self::STATUS_EXPIRED,
            'cancelled', 'canceled', 'cancelada', 'cancelado' => self::STATUS_CANCELLED,
            default => $normalized,
        };
    }

    private function resolveReservationPaymentSchedule(
        Reserva $reservation,
        ?AircraftAvailabilityBlock $ownBookedBlock = null,
        ?AircraftAvailabilityBlock $currentHold = null,
    ): array {
        if ($ownBookedBlock?->start_datetime && $ownBookedBlock?->end_datetime) {
            return [$ownBookedBlock->start_datetime->copy(), $ownBookedBlock->end_datetime->copy(), 'booked_block', null];
        }

        if ($currentHold?->start_datetime && $currentHold?->end_datetime) {
            return [$currentHold->start_datetime->copy(), $currentHold->end_datetime->copy(), 'hold_block', null];
        }

        try {
            [$quoteStart, $quoteEnd] = $reservation->quote
                ? $this->resolveQuoteWindow($reservation->quote->loadMissing('flightRequest.legs'))
                : [null, null];

            if ($quoteStart && $quoteEnd) {
                return [$quoteStart, $quoteEnd, 'accepted_quote', null];
            }
        } catch (Throwable) {
            // Seguimos con fuentes secundarias.
        }

        try {
            [$reservationStart, $reservationEnd] = $this->resolveReservationWindow($reservation);

            return [$reservationStart, $reservationEnd, 'flight_request_legs', null];
        } catch (Throwable) {
            return [null, null, null, 'reservation_missing_schedule'];
        }
    }

    private function buildReservationPaymentAvailabilityHoldPayload(
        ?AircraftAvailabilityBlock $hold,
        Reserva $reservation,
        ?string $invalidReason,
        bool $holdValid,
        ?AircraftAvailabilityBlock $ownBookedBlock = null,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): array {
        return [
            'id' => $hold?->id,
            'status' => $hold?->status,
            'aircraft_id' => $hold?->aircraft_id ?: $reservation->aircraft_id,
            'quote_id' => $hold?->quote_id ?: $reservation->quote_id,
            'flight_request_id' => $hold?->flight_request_id ?: $reservation->flight_request_id,
            'reservation_id' => $hold?->reservation_id ?: $reservation->id,
            'start_at' => ($hold?->start_datetime ?: $start)?->toIso8601String(),
            'end_at' => ($hold?->end_datetime ?: $end)?->toIso8601String(),
            'expires_at' => $hold?->hold_expires_at?->toIso8601String(),
            'released_at' => $hold?->released_at?->toIso8601String(),
            'booked_at' => $ownBookedBlock
                ? optional($ownBookedBlock->updated_at ?: $ownBookedBlock->created_at)?->toIso8601String()
                : null,
            'is_valid' => $holdValid,
            'invalid_reason' => $invalidReason,
        ];
    }

    private function aircraftStatusAllowsHold(mixed $status): bool
    {
        return in_array($this->normalizeStatus($status), self::RESERVABLE_AIRCRAFT_STATUSES, true);
    }

    private function normalizeStatus(mixed $status): string
    {
        return strtolower(trim((string) $status));
    }

    private function normalizeHoldDuration(int $minutesToHold): int
    {
        return min(max($minutesToHold, 1), 60);
    }

    public function resolveWindowFromPayload(array $payload = []): array
    {
        $legStartCandidates = [];
        $legEndCandidates = [];
        $legs = is_array($payload['legs'] ?? null) ? $payload['legs'] : [];

        foreach ($legs as $leg) {
            $departure = $this->toCarbon(
                $leg['start_datetime']
                    ?? $leg['departure_datetime']
                    ?? (($leg['start_date'] ?? null) ? (($leg['start_date'] ?? '').' '.($leg['start_time'] ?? '09:00')) : null)
                    ?? (($leg['departure_date'] ?? null) ? (($leg['departure_date'] ?? '').' '.($leg['departure_time'] ?? '09:00')) : null)
                    ?? (($leg['date'] ?? null) ? (($leg['date'] ?? '').' '.($leg['time'] ?? '09:00')) : null)
            );
            $arrival = $this->toCarbon($leg['arrival_datetime'] ?? null);

            if ($departure) {
                $legStartCandidates[] = $departure;
                $legEndCandidates[] = $departure;
            }

            if ($arrival) {
                $legEndCandidates[] = $arrival;
            }
        }

        $topLevelDeparture = $this->toCarbon(
            $payload['start_datetime']
                ?? $payload['departure_datetime']
                ?? (($payload['start_date'] ?? null) ? (($payload['start_date'] ?? '').' '.($payload['start_time'] ?? '09:00')) : null)
                ?? (($payload['departure_date'] ?? null) ? (($payload['departure_date'] ?? '').' '.($payload['departure_time'] ?? '09:00')) : null)
        );
        $topLevelReturn = $this->toCarbon($payload['return_datetime'] ?? null);
        $firstLegStart = collect($legStartCandidates)->sort()->first();
        $lastLegEnd = collect($legEndCandidates)->sort()->last();

        $start = $topLevelDeparture ?: $firstLegStart;
        $end = $topLevelReturn ?: $lastLegEnd;

        if ($topLevelReturn && $lastLegEnd && $lastLegEnd->greaterThan($topLevelReturn)) {
            $end = $lastLegEnd;
        }

        if ($start && ! $end && $lastLegEnd) {
            $end = $lastLegEnd;
        }

        return $this->normalizeWindow($start, $end);
    }

    public function resolveReservationWindow(Reserva $reservation): array
    {
        $reservation->loadMissing(['flightRequest.legs', 'legs']);

        $payload = [
            'departure_datetime' => $reservation->flightRequest?->departure_datetime,
            'return_datetime' => $reservation->flightRequest?->return_datetime,
            'legs' => collect($reservation->legs)
                ->map(fn ($leg) => [
                    'departure_datetime' => $leg->departure_datetime,
                    'arrival_datetime' => $leg->arrival_datetime,
                ])
                ->values()
                ->all(),
        ];

        if (empty($payload['legs']) && $reservation->flightRequest) {
            $payload['legs'] = collect($reservation->flightRequest->legs)
                ->map(fn ($leg) => [
                    'departure_datetime' => $leg->departure_datetime,
                    'arrival_datetime' => $leg->arrival_datetime,
                ])
                ->values()
                ->all();
        }

        return $this->resolveWindowFromPayload($payload);
    }

    public function resolveContractReservationWindow(Reserva $reservation, ?Aeronave $aircraft = null): array
    {
        $reservation->loadMissing(['flightRequest.legs', 'legs', 'quote', 'aircraft']);

        [$start, $end] = $this->resolveReservationWindow($reservation);
        $aircraft ??= $reservation->aircraft;

        return $this->applyOperationalWindowPadding(
            $start,
            $end,
            $aircraft,
            is_array($reservation->flightRequest?->pricing_context) ? $reservation->flightRequest->pricing_context : [],
        );
    }

    private function resolvePaidReservationWindow(Reserva $reservation, int $aircraftId): array
    {
        [$start, $end] = $this->resolveReservationWindow($reservation);

        $matchingHold = AircraftAvailabilityBlock::query()
            ->where('aircraft_id', $aircraftId)
            ->where(function ($query) use ($reservation) {
                $query->where('reservation_id', $reservation->id)
                    ->orWhere(function ($inner) use ($reservation) {
                        $inner->whereNull('reservation_id')
                            ->where('flight_request_id', $reservation->flight_request_id)
                            ->where('quote_id', $reservation->quote_id)
                            ->where('user_id', $reservation->client_id);
                    });
            })
            ->whereIn('status', [
                self::STATUS_HELD,
                self::STATUS_ACTIVE_LEGACY,
                self::STATUS_RELEASED,
                self::STATUS_BOOKED,
            ])
            ->orderByRaw("case when status in ('held', 'active', 'released') then 0 else 1 end")
            ->latest('id')
            ->first();

        if ($matchingHold?->start_datetime && $matchingHold?->end_datetime) {
            return [$matchingHold->start_datetime->copy(), $matchingHold->end_datetime->copy()];
        }

        return [$start, $end];
    }

    public function resolveFlightRequestWindow(SolicitudVuelo $flightRequest): array
    {
        $flightRequest->loadMissing('legs');

        $payload = [
            'departure_datetime' => $flightRequest->departure_datetime,
            'return_datetime' => $flightRequest->return_datetime,
            'departure_date' => optional($flightRequest->departure_datetime)->toDateString()
                ?? $this->normalizeDatePart($flightRequest->departure_date),
            'departure_time' => optional($flightRequest->departure_datetime)->format('H:i')
                ?? $flightRequest->departure_time,
            'return_date' => optional($flightRequest->return_datetime)->toDateString()
                ?? $this->normalizeDatePart($flightRequest->return_date),
            'return_time' => optional($flightRequest->return_datetime)->format('H:i')
                ?? $flightRequest->return_time,
            'legs' => collect($flightRequest->legs)
                ->map(fn ($leg) => [
                    'departure_datetime' => $leg->departure_datetime,
                    'arrival_datetime' => $leg->arrival_datetime,
                    'departure_date' => optional($leg->departure_datetime)->toDateString(),
                    'departure_time' => optional($leg->departure_datetime)->format('H:i'),
                ])
                ->values()
                ->all(),
        ];

        return $this->resolveWindowFromPayload($payload);
    }

    public function resolveOperationalFlightRequestWindow(SolicitudVuelo $flightRequest, ?Aeronave $aircraft = null): array
    {
        $flightRequest->loadMissing(['legs', 'assignedAircraft']);
        [$start, $end] = $this->resolveFlightRequestWindow($flightRequest);
        $aircraft ??= $flightRequest->assignedAircraft;

        return $this->applyOperationalWindowPadding(
            $start,
            $end,
            $aircraft,
            is_array($flightRequest->pricing_context) ? $flightRequest->pricing_context : [],
        );
    }

    private function normalizeWindow($requestedStart, $requestedEnd): array
    {
        $start = $this->toCarbon($requestedStart);
        if (! $start) {
            throw new RuntimeException('No se pudo resolver la fecha de inicio para validar disponibilidad de aeronave.');
        }

        $end = $this->toCarbon($requestedEnd);
        if (! $end || $end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addHours(4);
        }

        return [$start, $end];
    }

    private function conflictQuery(
        ?int $ignoreReservationId = null,
        ?int $ignoreBlockId = null,
        ?int $ignoreQuoteId = null,
        ?int $ignoreHoldId = null,
        array $ignoreBlockIds = [],
    ): Builder {
        $query = AircraftAvailabilityBlock::query();

        $this->applyBlockingStatusScope($query);
        $this->applyConflictExclusions(
            $query,
            $ignoreReservationId,
            $ignoreBlockId,
            $ignoreQuoteId,
            $ignoreHoldId,
            $ignoreBlockIds,
        );

        return $query;
    }

    private function applyBlockingStatusScope(Builder $query): Builder
    {
        return $query->where(function ($builder) {
            $builder->whereIn('status', [self::STATUS_BOOKED, self::STATUS_ACTIVE_LEGACY])
                ->orWhere(function ($inner) {
                    $inner->where('status', self::STATUS_HELD)
                        ->whereNotNull('hold_expires_at')
                        ->where('hold_expires_at', '>', now());
                });
        });
    }

    private function applyConflictExclusions(
        Builder $query,
        ?int $ignoreReservationId = null,
        ?int $ignoreBlockId = null,
        ?int $ignoreQuoteId = null,
        ?int $ignoreHoldId = null,
        array $ignoreBlockIds = [],
    ): Builder {
        return $query
            ->when($ignoreReservationId, fn ($builder) => $builder->where(function ($inner) use ($ignoreReservationId) {
                $inner->whereNull('reservation_id')
                    ->orWhere('reservation_id', '!=', $ignoreReservationId);
            }))
            ->when($ignoreQuoteId, fn ($builder) => $builder->where(function ($inner) use ($ignoreQuoteId) {
                $inner->whereNull('quote_id')
                    ->orWhere('quote_id', '!=', $ignoreQuoteId);
            }))
            ->when($ignoreBlockId, fn ($builder) => $builder->whereKeyNot($ignoreBlockId))
            ->when($ignoreHoldId, fn ($builder) => $builder->whereKeyNot($ignoreHoldId))
            ->when(! empty($ignoreBlockIds), fn ($builder) => $builder->whereKeyNot($ignoreBlockIds));
    }

    private function applyOperationalWindowPadding(
        Carbon $start,
        Carbon $end,
        ?Aeronave $aircraft = null,
        array $pricingContext = [],
    ): array {
        $preparationMinutes = max(0, (int) config('booking.aircraft_preparation_minutes', 30));
        $operationalMarginMinutes = max(0, (int) config('booking.aircraft_operational_margin_minutes', 30));
        $repositionPaddingMinutes = max(0, (int) config('booking.aircraft_reposition_padding_minutes', 30));
        $climbDescentMinutes = max(0, (int) ($pricingContext['client_climb_descent_minutes'] ?? $aircraft?->climb_descent_minutes ?? 0));
        $bufferHours = (float) ($pricingContext['buffer_hours'] ?? 0);
        $repositionHours = (float) ($pricingContext['repositioning_hours'] ?? 0);

        if ($bufferHours <= 0) {
            $bufferHours = max(0.5, (float) ($pricingContext['reserve_hours'] ?? 0));
        }

        $startPaddingMinutes = $preparationMinutes
            + $operationalMarginMinutes
            + (int) round($bufferHours * 60)
            + (int) round($repositionHours * 60)
            + $repositionPaddingMinutes;
        $endPaddingMinutes = $operationalMarginMinutes
            + (int) round($bufferHours * 60)
            + max(15, (int) round($climbDescentMinutes / 2));

        return [
            $start->copy()->subMinutes($startPaddingMinutes),
            $end->copy()->addMinutes($endPaddingMinutes),
        ];
    }

    private function toCarbon($value): ?Carbon
    {
        try {
            if ($value instanceof Carbon) {
                return $value->copy();
            }

            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
            }

            if ($value === null || $value === '') {
                return null;
            }

            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeDatePart(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        $normalizedValue = trim((string) ($value ?? ''));

        return $normalizedValue !== '' ? $normalizedValue : null;
    }

    private function buildPaidReservationBlockLogContext(Reserva $reservation, int $aircraftId, Carbon $start, Carbon $end): array
    {
        $aircraft = $reservation->aircraft;

        return [
            'reservation_id' => $reservation->id,
            'flight_request_id' => $reservation->flight_request_id,
            'aircraft_id' => $aircraftId,
            'registration' => trim((string) ($aircraft?->registration ?? '')),
            'model' => trim((string) ($aircraft?->model ?? '')),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
        ];
    }
}
