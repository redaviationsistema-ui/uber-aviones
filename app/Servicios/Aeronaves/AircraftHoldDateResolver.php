<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\Cotizacion;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

class AircraftHoldDateResolver
{
    public function buildPayload(array $requestData = [], ?Cotizacion $quote = null): array
    {
        $quote?->loadMissing(['flightRequest.legs']);
        $flightRequest = $quote?->flightRequest;

        $requestLegs = is_array($requestData['legs'] ?? null) ? $requestData['legs'] : [];
        $quoteLegs = collect($flightRequest?->legs ?? [])
            ->map(fn ($leg) => [
                'departure_datetime' => $leg->departure_datetime,
                'arrival_datetime' => $leg->arrival_datetime,
                'departure_date' => optional($leg->departure_datetime)->toDateString(),
                'departure_time' => optional($leg->departure_datetime)->format('H:i'),
            ])
            ->values()
            ->all();

        return [
            'start_datetime' => $requestData['start_datetime'] ?? null,
            'departure_datetime' => $requestData['departure_datetime'] ?? optional($flightRequest?->departure_datetime)->toDateTimeString(),
            'start_date' => $requestData['start_date'] ?? null,
            'start_time' => $requestData['start_time'] ?? null,
            'departure_date' => $requestData['departure_date']
                ?? optional($flightRequest?->departure_datetime)->toDateString()
                ?? $this->normalizeDatePart($flightRequest?->departure_date),
            'departure_time' => $requestData['departure_time']
                ?? optional($flightRequest?->departure_datetime)->format('H:i')
                ?? $flightRequest?->departure_time,
            'return_datetime' => $requestData['return_datetime'] ?? optional($flightRequest?->return_datetime)->toDateTimeString(),
            'return_date' => $requestData['return_date']
                ?? optional($flightRequest?->return_datetime)->toDateString()
                ?? $this->normalizeDatePart($flightRequest?->return_date),
            'return_time' => $requestData['return_time']
                ?? optional($flightRequest?->return_datetime)->format('H:i')
                ?? $flightRequest?->return_time,
            'legs' => ! empty($requestLegs) ? $requestLegs : $quoteLegs,
        ];
    }

    public function resolve(array $requestData = [], ?Cotizacion $quote = null): ?CarbonImmutable
    {
        $payload = $this->buildPayload($requestData, $quote);

        $candidates = [
            $payload['start_datetime'] ?? null,
            $payload['departure_datetime'] ?? null,
            $this->combine($payload['start_date'] ?? null, $payload['start_time'] ?? null),
            $this->combine($payload['departure_date'] ?? null, $payload['departure_time'] ?? null),
            data_get($payload, 'legs.0.start_datetime'),
            data_get($payload, 'legs.0.departure_datetime'),
            $this->combine(data_get($payload, 'legs.0.start_date'), data_get($payload, 'legs.0.start_time')),
            $this->combine(data_get($payload, 'legs.0.departure_date'), data_get($payload, 'legs.0.departure_time')),
            $this->combine(data_get($payload, 'legs.0.date'), data_get($payload, 'legs.0.time')),
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->parseCandidate($candidate);
            if ($resolved) {
                return $resolved;
            }
        }

        return null;
    }

    private function combine(mixed $date, mixed $time): ?string
    {
        $normalizedDate = trim((string) ($date ?? ''));
        if ($normalizedDate === '') {
            return null;
        }

        $normalizedTime = trim((string) ($time ?? '09:00')) ?: '09:00';

        return sprintf('%s %s', $normalizedDate, $normalizedTime);
    }

    private function normalizeDatePart(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        $normalizedValue = trim((string) ($value ?? ''));

        return $normalizedValue !== '' ? $normalizedValue : null;
    }

    private function parseCandidate(mixed $candidate): ?CarbonImmutable
    {
        try {
            if ($candidate instanceof CarbonImmutable) {
                return $candidate;
            }

            if ($candidate instanceof DateTimeInterface) {
                return CarbonImmutable::instance($candidate);
            }

            $normalizedCandidate = trim((string) ($candidate ?? ''));
            if ($normalizedCandidate === '') {
                return null;
            }

            return CarbonImmutable::parse($normalizedCandidate, 'America/Mexico_City');
        } catch (Throwable) {
            return null;
        }
    }
}
