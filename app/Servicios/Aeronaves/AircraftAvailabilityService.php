<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\Cotizacion;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class AircraftAvailabilityService
{
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
        $reservation->loadMissing(['flightRequest.legs', 'legs']);

        $aircraftId = (int) ($reservation->aircraft_id ?: $reservation->flightRequest?->assigned_aircraft_id);
        if ($aircraftId <= 0) {
            throw new RuntimeException('La reserva no tiene una aeronave asignada para bloquear disponibilidad.');
        }

        [$start, $end] = $this->resolveReservationWindow($reservation);
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

        AircraftAvailabilityBlock::query()
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

        return $block->fresh();
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

        return AircraftAvailabilityBlock::query()
            ->where('aircraft_id', $aircraftId)
            ->where(function ($query) {
                $query->whereIn('status', [self::STATUS_BOOKED, self::STATUS_ACTIVE_LEGACY])
                    ->orWhere(function ($inner) {
                        $inner->where('status', self::STATUS_HELD)
                            ->whereNotNull('hold_expires_at')
                            ->where('hold_expires_at', '>', now());
                    });
            })
            ->when($ignoreReservationId, fn ($query) => $query->where(function ($inner) use ($ignoreReservationId) {
                $inner->whereNull('reservation_id')
                    ->orWhere('reservation_id', '!=', $ignoreReservationId);
            }))
            ->when($ignoreQuoteId, fn ($query) => $query->where(function ($inner) use ($ignoreQuoteId) {
                $inner->whereNull('quote_id')
                    ->orWhere('quote_id', '!=', $ignoreQuoteId);
            }))
            ->when($ignoreBlockId, fn ($query) => $query->whereKeyNot($ignoreBlockId))
            ->when($ignoreHoldId, fn ($query) => $query->whereKeyNot($ignoreHoldId))
            ->where('start_datetime', '<', $end)
            ->where('end_datetime', '>', $start)
            ->exists();
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
            $builder->where(function ($query) {
                $query->whereIn('status', [self::STATUS_BOOKED, self::STATUS_ACTIVE_LEGACY])
                    ->orWhere(function ($inner) {
                        $inner->where('status', self::STATUS_HELD)
                            ->whereNotNull('hold_expires_at')
                            ->where('hold_expires_at', '>', now());
                    });
            })->where('start_datetime', '<', $end)
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
        int $minutesToHold = 15,
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

        return DB::transaction(function () use ($quote, $flightRequest, $userId, $reservation, $minutesToHold) {
            $aircraft = Aeronave::query()->lockForUpdate()->findOrFail($quote->aircraft_id);
            $this->expireStaleHoldsForAircraft((int) $aircraft->id);

            if (! $this->aircraftStatusAllowsHold($aircraft->status)) {
                throw new RuntimeException('La aeronave ya no esta activa para reservarse.');
            }

            [$start, $end] = $this->resolveQuoteWindow($quote);

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

            $minutesToHold = $this->normalizeHoldDuration($minutesToHold);

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

            $hold = AircraftAvailabilityBlock::query()->create($attributes);
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

    public function resolveQuoteWindow(Cotizacion $quote): array
    {
        $quote->loadMissing(['flightRequest.legs']);

        $payload = [
            'departure_datetime' => $quote->flightRequest?->departure_datetime,
            'return_datetime' => $quote->flightRequest?->return_datetime,
            'legs' => collect($quote->flightRequest?->legs ?? [])
                ->map(fn ($leg) => [
                    'departure_datetime' => $leg->departure_datetime,
                    'arrival_datetime' => $leg->arrival_datetime,
                ])->values()->all(),
        ];

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
        $startCandidates = [];
        $endCandidates = [];
        $legs = is_array($payload['legs'] ?? null) ? $payload['legs'] : [];

        foreach ($legs as $leg) {
            $departure = $this->toCarbon($leg['departure_datetime'] ?? (($leg['date'] ?? null) ? (($leg['date'] ?? '').' '.($leg['time'] ?? '09:00')) : null));
            $arrival = $this->toCarbon($leg['arrival_datetime'] ?? null);

            if ($departure) {
                $startCandidates[] = $departure;
                $endCandidates[] = $departure;
            }

            if ($arrival) {
                $endCandidates[] = $arrival;
            }
        }

        $topLevelDeparture = $this->toCarbon($payload['departure_datetime'] ?? null);
        $topLevelReturn = $this->toCarbon($payload['return_datetime'] ?? null);

        if ($topLevelDeparture) {
            $startCandidates[] = $topLevelDeparture;
            $endCandidates[] = $topLevelDeparture;
        }

        if ($topLevelReturn) {
            $endCandidates[] = $topLevelReturn;
        }

        $start = collect($startCandidates)->sort()->first();
        $end = collect($endCandidates)->sort()->last();

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
}
