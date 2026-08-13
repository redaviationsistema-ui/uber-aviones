<?php

namespace App\Servicios\Vuelos;

use App\Modelos\Aeropuerto;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class FlightRouteService
{
    private array $airportCache = [];

    private ?array $airportSearchColumns = null;

    private array $airportLabelCache = [];

    public function buildCanonicalRoute(array $payload): array
    {
        $tripType = $this->resolveTripType($payload);
        $definitions = $this->normalizeLegDefinitions($payload);

        if ($definitions === []) {
            $definitions[] = [
                'origin' => $this->extractAirportReference($payload, 'origin', ['origin', 'base_airport']),
                'destination' => $this->extractAirportReference($payload, 'destination', ['destination']),
                'departure_datetime' => $payload['departure_datetime'] ?? null,
            ];
        }

        $legs = [];

        foreach ($definitions as $index => $definition) {
            $origin = $this->requireAirport($definition['origin'] ?? null, 'origen', 'origin', $index);
            $destination = $this->requireAirport($definition['destination'] ?? null, 'destino', 'destination', $index);

            if ($this->airportsMatch($origin, $destination)) {
                [$reconciledOrigin, $reconciledDestination] = $this->reconcileConflictingAirports(
                    $definition['origin'] ?? null,
                    $definition['destination'] ?? null,
                );
                if ($reconciledOrigin && $reconciledDestination && ! $this->airportsMatch($reconciledOrigin, $reconciledDestination)) {
                    Log::warning('Canonical airport references reconciled', [
                        'leg_position' => $index + 1,
                        'original_airport_id' => $origin->id,
                        'resolved_origin_id' => $reconciledOrigin->id,
                        'resolved_destination_id' => $reconciledDestination->id,
                        'origin_code' => $reconciledOrigin->icao ?: $reconciledOrigin->iata,
                        'destination_code' => $reconciledDestination->icao ?: $reconciledDestination->iata,
                        'reason' => 'stale_structured_reference_conflicted_with_distinct_catalog_codes',
                    ]);
                    $origin = $reconciledOrigin;
                    $destination = $reconciledDestination;
                }
            }

            if ($this->airportsMatch($origin, $destination)) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'El aeropuerto de origen y destino del tramo '.($index + 1).' deben ser diferentes.',
                ], 422));
            }

            $legs[] = $this->buildLeg(
                count($legs) + 1,
                $origin,
                $destination,
                $definition['departure_datetime'] ?? null,
                $definition,
            );
        }

        $firstOrigin = $legs[0]['origin_airport'] ?? null;
        $lastDestination = $legs[count($legs) - 1]['destination_airport'] ?? null;

        if (
            $tripType === 'round_trip'
            && count($legs) === 1
            && is_array($firstOrigin)
            && is_array($lastDestination)
        ) {
            $legs[] = $this->buildLeg(
                2,
                new Aeropuerto($lastDestination),
                new Aeropuerto($firstOrigin),
                $payload['return_datetime'] ?? $payload['return_date'] ?? null,
            );
        }

        if (
            $tripType === 'multi_leg'
            && $this->shouldReturnToOrigin($payload)
            && is_array($firstOrigin)
            && is_array($lastDestination)
            && ! $this->airportPayloadsMatch($firstOrigin, $lastDestination)
        ) {
            $legs[] = $this->buildLeg(
                count($legs) + 1,
                new Aeropuerto($lastDestination),
                new Aeropuerto($firstOrigin),
                $payload['return_datetime'] ?? $payload['return_date'] ?? null,
            );
        }

        return [
            'trip_type' => $tripType,
            'origin' => $legs[0]['origin'] ?? null,
            'destination' => $legs[0]['destination'] ?? null,
            'legs' => $legs,
            'distance_km' => (float) collect($legs)->sum('distance_km'),
            'distance_nm' => (float) collect($legs)->sum('distance_nm'),
            'max_leg_distance_km' => (float) collect($legs)->max('distance_km'),
            'route_signature' => $this->routeSignature($legs),
        ];
    }

    public function resolveAirport(string $code): ?Aeropuerto
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return null;
        }

        if (array_key_exists($normalized, $this->airportCache)) {
            return $this->airportCache[$normalized];
        }

        $airport = Aeropuerto::query()
            ->where('status', 'active')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function ($query) use ($normalized) {
                foreach ($this->activeAirportSearchColumns() as $index => $column) {
                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $query->{$method}("UPPER({$column}) = ?", [$normalized]);
                }
            })
            ->first();

        return $this->airportCache[$normalized] = $airport;
    }

    public function referencesMatch(string $left, string $right): bool
    {
        $leftAirport = $this->resolveLegacyReference($left);
        $rightAirport = $this->resolveLegacyReference($right);

        return $leftAirport && $rightAirport && $leftAirport->is($rightAirport);
    }

    public function resolveTripType(array $payload): string
    {
        $value = mb_strtolower(trim((string) ($payload['trip_type'] ?? $payload['trip_label'] ?? '')));
        $normalized = match ($value) {
            'redondo', 'round_trip', 'roundtrip' => 'round_trip',
            'multi-destino', 'multidestino', 'multi_city', 'multi_leg' => 'multi_leg',
            default => 'one_way',
        };

        if ($normalized !== 'one_way') {
            return $normalized;
        }

        return filter_var($payload['round_trip'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($payload['return'] ?? false, FILTER_VALIDATE_BOOL)
                ? 'round_trip'
                : 'one_way';
    }

    private function normalizeLegDefinitions(array $payload): array
    {
        $explicit = $this->normalizeExplicitLegs($payload['legs'] ?? null);
        if ($explicit !== []) {
            return $explicit;
        }

        $origin = $this->extractAirportReference($payload, 'origin', ['origin', 'base_airport']);
        $destination = $this->extractAirportReference($payload, 'destination', ['destination']);
        $definitions = [];
        $points = array_values(array_filter([$origin]));

        foreach (($payload['requirements'] ?? []) as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $requirementOrigin = $this->extractAirportReference($requirement, 'origin', ['origin', 'origin_code', 'from', 'from_code', 'origin_airport']);
            $requirementDestination = $this->extractAirportReference($requirement, 'destination', ['destination', 'destination_code', 'to', 'to_code', 'destination_airport']);

            if ($requirementOrigin && $requirementDestination) {
                $definitions[] = [
                    'origin' => $requirementOrigin,
                    'destination' => $requirementDestination,
                    'departure_datetime' => $requirement['departure_datetime'] ?? null,
                    ...$this->extractTimingHints($requirement),
                ];

                continue;
            }

            $waypoint = $requirementDestination
                ?: $requirementOrigin
                ?: $this->extractAirportCode($requirement, ['airport', 'airport_code', 'code', 'icao', 'iata']);

            if ($waypoint && end($points) !== $waypoint) {
                $points[] = $waypoint;
            }
        }

        if ($definitions !== []) {
            $first = $definitions[0];
            if ($origin && $destination && ($first['origin'] !== $origin || $first['destination'] !== $destination)) {
                array_unshift($definitions, [
                    'origin' => $origin,
                    'destination' => $destination,
                    'departure_datetime' => $payload['departure_datetime'] ?? null,
                    ...$this->extractTimingHints($payload),
                ]);
            }

            return $definitions;
        }

        if ($destination && ! in_array($destination, $points, true)) {
            $points[] = $destination;
        }

        for ($index = 0; $index < count($points) - 1; $index++) {
            $definitions[] = [
                'origin' => $points[$index],
                'destination' => $points[$index + 1],
                'departure_datetime' => $index === 0
                    ? ($payload['departure_datetime'] ?? null)
                    : data_get($payload, 'requirements.'.($index - 1).'.departure_datetime'),
                ...$this->extractTimingHints($index === 0 ? $payload : (array) data_get($payload, 'requirements.'.($index - 1), [])),
            ];
        }

        return $definitions;
    }

    private function normalizeExplicitLegs(mixed $legs): array
    {
        if (! is_array($legs)) {
            return [];
        }

        return collect($legs)
            ->filter(fn ($leg) => is_array($leg))
            ->map(function (array $leg) {
                return [
                    'origin' => $this->extractAirportReference($leg, 'origin', ['origin', 'origin_code', 'from', 'from_code', 'origin_airport']),
                    'destination' => $this->extractAirportReference($leg, 'destination', ['destination', 'destination_code', 'to', 'to_code', 'destination_airport']),
                    'departure_datetime' => $leg['departure_datetime'] ?? null,
                    ...$this->extractTimingHints($leg),
                ];
            })
            ->filter(fn (array $leg) => filled($leg['origin']) && filled($leg['destination']))
            ->values()
            ->all();
    }

    private function requireAirport(mixed $reference, string $role, string $side, int $legIndex): Aeropuerto
    {
        $airport = $this->resolveAirportReference($reference);
        if (! $airport) {
            $safeReference = is_array($reference) ? $reference : ['code' => $reference];
            Log::warning('Canonical airport resolution rejected', [
                'role' => $role,
                'leg_position' => $legIndex + 1,
                'received_fields' => array_values(array_intersect(
                    array_keys($safeReference),
                    ['id', 'airport_id', 'icao', 'icao_code', 'iata', 'iata_code', 'code', 'name']
                )),
                'attempted_id' => $safeReference['id'] ?? $safeReference['airport_id'] ?? null,
                'attempted_code' => $safeReference['icao'] ?? $safeReference['icao_code']
                    ?? $safeReference['iata'] ?? $safeReference['iata_code'] ?? $safeReference['code'] ?? null,
                'table' => 'airports',
                'reason' => $this->airportRejectionReason($safeReference),
            ]);

            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'AIRPORT_NOT_FOUND',
                'message' => "No encontramos el aeropuerto de {$role} del tramo ".($legIndex + 1).'.',
                'details' => [
                    'leg_index' => $legIndex,
                    'side' => $side,
                    'attempted_icao' => $this->normalizedReferenceValue($safeReference, ['icao', 'icao_code']),
                    'attempted_iata' => $this->normalizedReferenceValue($safeReference, ['iata', 'iata_code']),
                ],
            ], 422));
        }
        abort_if(
            $airport->latitude === null || $airport->longitude === null,
            422,
            "El aeropuerto de {$role} del tramo ".($legIndex + 1).' no tiene coordenadas.',
        );

        return $airport;
    }

    private function reconcileConflictingAirports(mixed $originReference, mixed $destinationReference): array
    {
        $origin = $this->resolveReferenceWithoutId($originReference);
        $destination = $this->resolveReferenceWithoutId($destinationReference);

        return [$origin, $destination];
    }

    private function resolveReferenceWithoutId(mixed $reference): ?Aeropuerto
    {
        $reference = is_array($reference) ? $reference : ['code' => $reference];

        foreach (['code', 'icao', 'icao_code', 'iata', 'iata_code'] as $key) {
            $code = strtoupper(trim((string) ($reference[$key] ?? '')));
            if ($code !== '') {
                $airport = $this->resolveAirport($code);
                if ($airport) {
                    return $airport;
                }
            }
        }

        $label = $reference['legacy_label'] ?? $reference['name'] ?? null;

        return filled($label)
            ? $this->resolveAirportReference(['legacy_label' => $label])
            : null;
    }

    private function resolveLegacyReference(string $value): ?Aeropuerto
    {
        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return null;
        }

        return preg_match('/^[A-Z0-9]{3,4}$/', $normalized) === 1
            ? $this->resolveAirportReference(['code' => $normalized])
            : $this->resolveAirportReference(['legacy_label' => $value]);
    }

    private function normalizedReferenceValue(array $reference, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = strtoupper(trim((string) ($reference[$key] ?? '')));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveAirportReference(mixed $reference): ?Aeropuerto
    {
        $reference = is_array($reference) ? $reference : ['code' => $reference];
        $id = filter_var($reference['id'] ?? $reference['airport_id'] ?? null, FILTER_VALIDATE_INT);

        if ($id) {
            return Aeropuerto::query()
                ->whereKey($id)
                ->where('status', 'active')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->first();
        }

        foreach (['icao', 'icao_code', 'iata', 'iata_code', 'code'] as $key) {
            $code = trim((string) ($reference[$key] ?? ''));
            if ($code !== '') {
                return $this->resolveAirport($code);
            }
        }

        $label = trim((string) ($reference['name'] ?? $reference['legacy_label'] ?? ''));
        if ($label === '') {
            return null;
        }

        $matches = $this->airportsByExactLabel($label);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function airportRejectionReason(array $reference): string
    {
        $query = Aeropuerto::query();
        $id = filter_var($reference['id'] ?? $reference['airport_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            $query->whereKey($id);
        } else {
            $code = collect(['icao', 'icao_code', 'iata', 'iata_code', 'code'])
                ->map(fn (string $key) => trim((string) ($reference[$key] ?? '')))
                ->first(fn (string $value) => $value !== '');
            if ($code) {
                $normalized = strtoupper($code);
                $query->where(function ($query) use ($normalized) {
                    foreach ($this->activeAirportSearchColumns() as $index => $column) {
                        $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                        $query->{$method}("UPPER({$column}) = ?", [$normalized]);
                    }
                });
            } else {
                $label = trim((string) ($reference['name'] ?? $reference['legacy_label'] ?? ''));
                if ($label === '') {
                    return 'missing_identifier';
                }
                $matches = $this->airportsByExactLabel($label);

                return $matches->count() > 1 ? 'ambiguous_exact_name' : 'airport_not_found';
            }
        }

        $airport = $query->first();
        if (! $airport) {
            return 'airport_not_found';
        }
        if ($airport->status !== 'active') {
            return 'airport_inactive';
        }
        if ($airport->latitude === null || $airport->longitude === null) {
            return 'airport_missing_coordinates';
        }

        return 'airport_not_eligible';
    }

    private function normalizeCatalogLabel(mixed $value): string
    {
        return Str::of((string) ($value ?? ''))->trim()->lower()->ascii()->squish()->value();
    }

    private function airportsByExactLabel(string $label)
    {
        $normalized = $this->normalizeCatalogLabel($label);
        if (array_key_exists($normalized, $this->airportLabelCache)) {
            return $this->airportLabelCache[$normalized];
        }

        $raw = mb_strtolower(trim($label));
        $candidates = array_values(array_unique(array_filter([$raw, $normalized])));

        return $this->airportLabelCache[$normalized] = Aeropuerto::query()
            ->where('status', 'active')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function ($query) use ($candidates) {
                foreach ($candidates as $candidate) {
                    $query
                        ->orWhereRaw('LOWER(TRIM(name)) = ?', [$candidate])
                        ->orWhereRaw('LOWER(TRIM(city)) = ?', [$candidate]);
                }
            })
            ->limit(2)
            ->get()
            ->values();
    }

    private function buildLeg(
        int $position,
        Aeropuerto $origin,
        Aeropuerto $destination,
        mixed $departure,
        array $context = []
    ): array
    {
        $distanceKm = $this->distance(
            (float) $origin->latitude,
            (float) $origin->longitude,
            (float) $destination->latitude,
            (float) $destination->longitude,
            6371.0,
        );
        $distanceNm = $this->distance(
            (float) $origin->latitude,
            (float) $origin->longitude,
            (float) $destination->latitude,
            (float) $destination->longitude,
            3440.065,
        );

        return array_filter([
            'position' => $position,
            'origin' => $origin->icao ?: $origin->iata,
            'destination' => $destination->icao ?: $destination->iata,
            'origin_airport' => $this->airportPayload($origin),
            'destination_airport' => $this->airportPayload($destination),
            'distance_km' => round($distanceKm),
            'distance_nm' => round($distanceNm),
            'international' => strtoupper((string) $origin->country) !== strtoupper((string) $destination->country),
            'departure_datetime' => $departure,
            ...$this->extractTimingHints($context),
        ], static fn ($value) => $value !== null);
    }

    private function extractTimingHints(array $payload): array
    {
        $timingHints = [];

        foreach ([
            'duration_minutes',
            'estimated_minutes',
            'quoted_minutes',
            'flight_minutes',
            'leg_minutes',
            'duration_hours',
        ] as $field) {
            $value = $payload[$field] ?? null;
            if (! is_numeric($value)) {
                continue;
            }

            $numericValue = (float) $value;
            if ($numericValue <= 0) {
                continue;
            }

            $timingHints[$field] = $field === 'duration_hours'
                ? round($numericValue, 4)
                : round($numericValue, 2);
        }

        return $timingHints;
    }

    private function distance(float $originLat, float $originLng, float $destinationLat, float $destinationLng, float $radius): float
    {
        $latDelta = deg2rad($destinationLat - $originLat);
        $lngDelta = deg2rad($destinationLng - $originLng);
        $originLat = deg2rad($originLat);
        $destinationLat = deg2rad($destinationLat);
        $angle = sin($latDelta / 2) ** 2
            + cos($originLat) * cos($destinationLat) * sin($lngDelta / 2) ** 2;

        return $radius * 2 * atan2(sqrt($angle), sqrt(1 - $angle));
    }

    private function airportPayload(Aeropuerto $airport): array
    {
        return [
            'id' => $airport->id,
            'icao' => $airport->icao,
            'iata' => $airport->iata,
            'name' => $airport->name,
            'city' => $airport->city,
            'country' => $airport->country,
            'latitude' => $airport->latitude,
            'longitude' => $airport->longitude,
            'climb_descent_adjustment_minutes' => (int) ($airport->climb_descent_adjustment_minutes ?? 0),
        ];
    }

    private function extractAirportCode(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return strtoupper(trim($value));
            }
            if (is_array($value)) {
                foreach (['icao', 'iata', 'code'] as $nested) {
                    if (is_string($value[$nested] ?? null) && trim($value[$nested]) !== '') {
                        return strtoupper(trim($value[$nested]));
                    }
                }
            }
        }

        return null;
    }

    private function extractAirportReference(array $payload, string $side, array $legacyKeys): array
    {
        $object = $payload["{$side}_airport"]
            ?? $payload[$side.'Airport']
            ?? null;
        $object = is_array($object) ? $object : [];

        $id = $payload["{$side}_airport_id"]
            ?? $payload[$side.'AirportId']
            ?? $object['id']
            ?? $object['airport_id']
            ?? null;
        $icao = $payload["{$side}_icao"]
            ?? $payload["{$side}_icao_code"]
            ?? $object['icao']
            ?? $object['icao_code']
            ?? $object['code']
            ?? null;
        $iata = $payload["{$side}_iata"]
            ?? $payload["{$side}_iata_code"]
            ?? $object['iata']
            ?? $object['iata_code']
            ?? null;
        $legacyCode = $this->extractAirportCode($payload, $legacyKeys);
        $normalizedLegacy = strtoupper(trim((string) ($legacyCode ?? '')));
        $legacyIsCode = preg_match('/^[A-Z0-9]{3,4}$/', $normalizedLegacy) === 1;

        return array_filter([
            'id' => $id,
            'icao' => $icao,
            'iata' => $iata,
            'code' => $legacyIsCode ? $normalizedLegacy : null,
            'name' => $object['name'] ?? $payload["{$side}_airport_name"] ?? null,
            'legacy_label' => ! $legacyIsCode ? $legacyCode : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function shouldReturnToOrigin(array $payload): bool
    {
        foreach (['return_to_origin', 'return_to_start', 'close_route'] as $key) {
            if (array_key_exists($key, $payload)) {
                return filter_var($payload[$key], FILTER_VALIDATE_BOOL);
            }
        }

        return ! filter_var($payload['open_route'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function airportsMatch(Aeropuerto $left, Aeropuerto $right): bool
    {
        return $this->airportPayloadsMatch($this->airportPayload($left), $this->airportPayload($right));
    }

    private function airportPayloadsMatch(array $left, array $right): bool
    {
        $leftCodes = array_filter([strtoupper((string) ($left['icao'] ?? '')), strtoupper((string) ($left['iata'] ?? ''))]);
        $rightCodes = array_filter([strtoupper((string) ($right['icao'] ?? '')), strtoupper((string) ($right['iata'] ?? ''))]);

        return array_intersect($leftCodes, $rightCodes) !== [];
    }

    private function routeSignature(array $legs): string
    {
        $codes = [];
        foreach ($legs as $index => $leg) {
            if ($index === 0) {
                $codes[] = $leg['origin'];
            }
            $codes[] = $leg['destination'];
        }

        return implode('>', array_filter($codes));
    }

    private function activeAirportSearchColumns(): array
    {
        if ($this->airportSearchColumns !== null) {
            return $this->airportSearchColumns;
        }

        $columns = ['icao', 'iata'];
        foreach (['icao_code', 'iata_code'] as $column) {
            if (Schema::hasColumn('airports', $column)) {
                $columns[] = $column;
            }
        }

        return $this->airportSearchColumns = $columns;
    }
}
