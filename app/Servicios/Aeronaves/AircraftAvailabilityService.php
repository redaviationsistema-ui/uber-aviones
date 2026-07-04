<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\Reserva;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;


class AircraftAvailabilityService
{
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

        $attributes = [
            'start_datetime' => $start,
            'end_datetime' => $end,
            'status' => 'active',
            'reason' => 'Reserva pagada y aeronave apartada para el horario confirmado.',
        ];

        if ($this->availabilityBlockSupports('block_type')) {
            $attributes['block_type'] = 'reservation';
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

        $staleBlockReleasePayload = [
            'status' => 'released',
            'reason' => 'Bloqueo anterior liberado por reprogramacion de la reserva.',
        ];

        if ($this->availabilityBlockSupports('released_at')) {
            $staleBlockReleasePayload['released_at'] = now();
        }

        AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'active')
            ->whereKeyNot($block->id)
            ->update($staleBlockReleasePayload);

        return $block->fresh();
    }

    public function releaseReservationBlock(Reserva $reservation, ?string $reasonOverride = null): ?AircraftAvailabilityBlock
    {
        $reservation->loadMissing(['flightRequest', 'latestPayment']);

        $blocks = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'active')
            ->get();

        if ($blocks->isEmpty()) {
            return null;
        }

        $paymentStatus = strtolower(trim((string) ($reservation->flightRequest?->payment_status ?: $reservation->latestPayment?->status ?: '')));
        $reservationStatus = strtolower(trim((string) ($reservation->status ?? '')));

        $releasedStatus = in_array($paymentStatus, ['cancelled', 'failed', 'refunded'], true)
            || in_array($reservationStatus, ['cancelled', 'canceled'], true)
            ? 'cancelled'
            : 'released';

        $reason = match ($releasedStatus) {
            'cancelled' => 'Bloqueo liberado por cancelacion, fallo o reembolso de la reserva.',
            default => 'Bloqueo liberado manualmente para esta reserva.',
        };

        $releasePayload = [
            'status' => $releasedStatus,
            'reason' => $reasonOverride ?: $reason,
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
    ): bool {
        [$start, $end] = $this->normalizeWindow($requestedStart, $requestedEnd);

        return AircraftAvailabilityBlock::query()
            ->where('aircraft_id', $aircraftId)
            ->where('status', 'active')
            ->when($ignoreReservationId, fn ($query) => $query->where(function ($inner) use ($ignoreReservationId) {
                $inner->whereNull('reservation_id')
                    ->orWhere('reservation_id', '!=', $ignoreReservationId);
            }))
            ->when($ignoreBlockId, fn ($query) => $query->whereKeyNot($ignoreBlockId))
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
    ): void {
        if (! $this->aircraftHasConflictExcluding($aircraftId, $requestedStart, $requestedEnd, $ignoreReservationId, $ignoreBlockId)) {
            return;
        }

        throw new RuntimeException('Esta aeronave ya no está disponible para el horario seleccionado.');
    }

    public function excludeConflictingAircraft(Builder $query, $requestedStart, $requestedEnd): Builder
    {
        [$start, $end] = $this->normalizeWindow($requestedStart, $requestedEnd);

        return $query->whereDoesntHave('availabilityBlocks', function (Builder $builder) use ($start, $end) {
            $builder->where('status', 'active')
                ->where('start_datetime', '<', $end)
                ->where('end_datetime', '>', $start);
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
            'start_datetime' => $start,
            'end_datetime' => $end,
            'status' => 'active',
            'reason' => trim((string) ($payload['reason'] ?? 'Bloqueo manual administrativo.')),
        ];

        if ($this->availabilityBlockSupports('block_type')) {
            $attributes['block_type'] = (string) ($payload['block_type'] ?? 'manual');
        }

        return AircraftAvailabilityBlock::query()->create($attributes);
    }

    public function releaseBlock(AircraftAvailabilityBlock $block, string $reason = 'Bloqueo liberado manualmente.'): AircraftAvailabilityBlock
    {
        $releasePayload = [
            'status' => 'released',
            'reason' => $reason,
        ];

        if ($this->availabilityBlockSupports('released_at')) {
            $releasePayload['released_at'] = now();
        }

        $block->update($releasePayload);

        return $block->fresh();
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
