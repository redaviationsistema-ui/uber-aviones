<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Servicios\Vuelos\DynamicFlightDurationService;
use App\Servicios\Vuelos\FlightDurationService;
use App\Servicios\Vuelos\FlightRouteService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AircraftRepositioningService
{
    public function __construct(
        private readonly FlightRouteService $flightRouteService,
        private readonly FlightDurationService $flightDurationService,
        private readonly DynamicFlightDurationService $dynamicFlightDurationService,
    ) {}

    public function configuredSearchRadiiNm(): array
    {
        $configured = config('aviation.repositioning.search_radii_nm');
        $fallback = config('aviation.repositioning.default_search_radii_nm', []);

        if (! is_array($configured)) {
            return is_array($fallback) ? $fallback : [];
        }

        $radii = collect($configured)
            ->map(fn (mixed $value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($radii !== []) {
            return $radii;
        }

        return is_array($fallback) ? array_values($fallback) : [];
    }

    public function maxCandidatesPerRadius(): int
    {
        $configured = (int) config(
            'aviation.repositioning.max_candidates_per_radius',
            config('aviation.repositioning.default_max_candidates_per_radius', 1)
        );

        return max(1, $configured);
    }

    public function exactMatchContext(Aeronave $aircraft, Aeropuerto $originAirport): array
    {
        $baseAirport = $this->resolveAircraftOperationalAirport($aircraft);

        return [
            'based_at_origin' => true,
            'requires_repositioning' => false,
            'apply_repositioning_pricing' => false,
            'selected_radius_nm' => null,
            'aircraft_base_airport' => $baseAirport ? $this->airportPayload($baseAirport) : null,
            'base_airport_code' => $aircraft->resolvedBaseAirportCode() ?? $aircraft->base_airport,
            'origin_airport' => $this->airportPayload($originAirport),
            'repositioning_distance_km' => 0.0,
            'repositioning_distance_nm' => 0.0,
            'return_to_base_distance_km' => 0.0,
            'return_to_base_distance_nm' => 0.0,
            'repositioning' => $this->emptySegmentPayload(),
            'return_to_base' => $this->emptySegmentPayload(['required' => false]),
        ];
    }

    public function nearbyCandidateContexts(
        Collection $aircraft,
        Aeropuerto $originAirport,
        array $canonicalRoute,
    ): Collection {
        return $aircraft
            ->map(function (Aeronave $candidate) use ($originAirport, $canonicalRoute): ?array {
                $context = $this->buildNearbyContext($candidate, $originAirport, $canonicalRoute);
                if ($context === null) {
                    return null;
                }

                return [
                    'aircraft' => $candidate,
                    'operational_context' => $context,
                    'repositioning_distance_nm' => (float) ($context['repositioning_distance_nm'] ?? 0.0),
                ];
            })
            ->filter()
            ->sortBy([
                fn (array $item) => (float) ($item['repositioning_distance_nm'] ?? 0.0),
                fn (array $item) => (int) ($item['aircraft']->id ?? 0),
            ])
            ->values();
    }

    public function withinRadius(Collection $candidates, int $radiusNm): Collection
    {
        return $candidates
            ->filter(fn (array $candidate) => (float) ($candidate['repositioning_distance_nm'] ?? INF) <= $radiusNm)
            ->take($this->maxCandidatesPerRadius())
            ->values();
    }

    public function adjustedWindow(
        CarbonInterface|string|null $requestedStart,
        CarbonInterface|string|null $requestedEnd,
        array $context,
    ): array {
        $start = $this->toCarbon($requestedStart);
        $end = $this->toCarbon($requestedEnd);

        $repositioningOperationalHours = (float) data_get($context, 'repositioning.operational_hours', 0);
        $returnOperationalHours = (float) data_get($context, 'return_to_base.operational_hours', 0);
        $preparationMinutes = max(0, (int) config('booking.aircraft_preparation_minutes', 0));
        $operationalMarginMinutes = max(0, (int) config('booking.aircraft_operational_margin_minutes', 0));
        $repositionPaddingMinutes = max(0, (int) config('booking.aircraft_reposition_padding_minutes', 0));

        if ($start && $repositioningOperationalHours > 0) {
            $start = $start->copy()->subMinutes(
                (int) ceil($repositioningOperationalHours * 60) + $preparationMinutes + $operationalMarginMinutes + $repositionPaddingMinutes
            );
        }

        if ($end && $returnOperationalHours > 0) {
            $end = $end->copy()->addMinutes(
                (int) ceil($returnOperationalHours * 60) + $operationalMarginMinutes
            );
        }

        return [$start, $end];
    }

    private function buildNearbyContext(
        Aeronave $aircraft,
        Aeropuerto $originAirport,
        array $canonicalRoute,
    ): ?array {
        $baseAirport = $this->resolveAircraftOperationalAirport($aircraft);
        if (! $baseAirport || $baseAirport->latitude === null || $baseAirport->longitude === null) {
            return null;
        }

        if ($originAirport->latitude === null || $originAirport->longitude === null) {
            return null;
        }

        if ($this->flightRouteService->referencesMatch(
            (string) ($baseAirport->icao ?: $baseAirport->iata),
            (string) $originAirport->icao
        )) {
            return null;
        }

        $clientLegs = array_values($canonicalRoute['legs'] ?? []);
        $firstLeg = $clientLegs[0] ?? null;
        $lastLeg = $clientLegs[count($clientLegs) - 1] ?? null;
        if (! is_array($firstLeg) || ! is_array($lastLeg)) {
            return null;
        }

        $firstOrigin = new Aeropuerto($firstLeg['origin_airport'] ?? []);
        $lastDestination = new Aeropuerto($lastLeg['destination_airport'] ?? []);

        $repositioning = $this->segmentPayload($aircraft, $baseAirport, $firstOrigin);
        $returnToBase = $this->segmentPayload($aircraft, $lastDestination, $baseAirport);

        return [
            'based_at_origin' => false,
            'requires_repositioning' => true,
            'apply_repositioning_pricing' => true,
            'selected_radius_nm' => null,
            'aircraft_base_airport' => $this->airportPayload($baseAirport),
            'base_airport_code' => $aircraft->resolvedBaseAirportCode() ?? $aircraft->base_airport,
            'origin_airport' => $this->airportPayload($originAirport),
            'repositioning_distance_km' => (float) ($repositioning['distance_km'] ?? 0.0),
            'repositioning_distance_nm' => (float) ($repositioning['distance_nm'] ?? 0.0),
            'return_to_base_distance_km' => (float) ($returnToBase['distance_km'] ?? 0.0),
            'return_to_base_distance_nm' => (float) ($returnToBase['distance_nm'] ?? 0.0),
            'repositioning' => $repositioning,
            'return_to_base' => [...$returnToBase, 'required' => (float) ($returnToBase['distance_nm'] ?? 0.0) > 0],
        ];
    }

    private function segmentPayload(Aeronave $aircraft, Aeropuerto $origin, Aeropuerto $destination): array
    {
        if ($this->flightRouteService->referencesMatch(
            (string) ($origin->icao ?: $origin->iata),
            (string) ($destination->icao ?: $destination->iata)
        )) {
            return $this->emptySegmentPayload([
                'origin_airport_id' => $origin->id,
                'destination_airport_id' => $destination->id,
                'origin_iata' => $origin->iata,
                'origin_icao' => $origin->icao,
                'destination_iata' => $destination->iata,
                'destination_icao' => $destination->icao,
            ]);
        }

        $route = $this->flightRouteService->buildCanonicalRoute([
            'origin' => $origin->icao ?: $origin->iata,
            'destination' => $destination->icao ?: $destination->iata,
            'trip_type' => 'one_way',
        ]);
        $leg = $route['legs'][0] ?? null;

        if (! is_array($leg)) {
            return $this->emptySegmentPayload();
        }

        $durationService = $this->useOperationalFlightTimeModel()
            ? $this->dynamicFlightDurationService
            : $this->flightDurationService;
        $legPricing = $durationService->calculateLeg(
            $aircraft,
            new Aeropuerto($leg['origin_airport'] ?? []),
            new Aeropuerto($leg['destination_airport'] ?? []),
            (float) ($leg['distance_km'] ?? 0),
            (float) ($leg['distance_nm'] ?? 0),
            false,
        );

        return [
            'origin_airport_id' => data_get($leg, 'origin_airport.id'),
            'destination_airport_id' => data_get($leg, 'destination_airport.id'),
            'origin_iata' => data_get($leg, 'origin_airport.iata'),
            'origin_icao' => data_get($leg, 'origin_airport.icao'),
            'destination_iata' => data_get($leg, 'destination_airport.iata'),
            'destination_icao' => data_get($leg, 'destination_airport.icao'),
            'distance_km' => round((float) ($leg['distance_km'] ?? 0.0), 2),
            'distance_nm' => round((float) ($leg['distance_nm'] ?? 0.0), 2),
            'flight_hours' => round((float) ($legPricing['direct_air_time_hours'] ?? 0.0), 4),
            'operational_hours' => round((float) ($legPricing['real_flight_hours'] ?? 0.0), 4),
            'billable_hours' => round((float) ($legPricing['billable_hours'] ?? 0.0), 4),
            'billable_minutes' => round((float) ($legPricing['billable_minutes'] ?? 0.0), 2),
            'cost' => round((float) ($legPricing['leg_cost'] ?? 0.0), 2),
        ];
    }

    private function useOperationalFlightTimeModel(): bool
    {
        return (bool) config('vuelos.dynamic_flight_time_enabled', false)
            && (string) config('vuelos.flight_time_model', 'direct') === 'operational';
    }

    private function resolveAircraftOperationalAirport(Aeronave $aircraft): ?Aeropuerto
    {
        if (method_exists($aircraft, 'currentAirport')) {
            $aircraft->loadMissing('currentAirport');

            if ($aircraft->currentAirport instanceof Aeropuerto) {
                return $aircraft->currentAirport;
            }
        }

        $aircraft->loadMissing('baseAirport');

        if ($aircraft->baseAirport instanceof Aeropuerto) {
            return $aircraft->baseAirport;
        }

        $legacyReference = trim((string) ($aircraft->base_airport ?? ''));

        return $legacyReference !== ''
            ? $this->resolveLegacyBaseAirport($legacyReference)
            : null;
    }

    private function resolveLegacyBaseAirport(string $reference): ?Aeropuerto
    {
        $airport = $this->flightRouteService->resolveAirport($reference);
        if ($airport instanceof Aeropuerto) {
            return $airport;
        }

        $normalized = Str::of($reference)->trim()->lower()->ascii()->squish()->value();
        if ($normalized === '') {
            return null;
        }

        $matches = Aeropuerto::query()
            ->where('status', 'active')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function ($query) use ($normalized) {
                $query
                    ->orWhereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(city)) = ?', [$normalized]);
            })
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function airportPayload(Aeropuerto $airport): array
    {
        return [
            'id' => $airport->id,
            'iata' => $airport->iata,
            'icao' => $airport->icao,
            'name' => $airport->name,
            'city' => $airport->city,
            'country' => $airport->country,
            'latitude' => $airport->latitude,
            'longitude' => $airport->longitude,
        ];
    }

    private function emptySegmentPayload(array $overrides = []): array
    {
        return [
            'origin_airport_id' => null,
            'destination_airport_id' => null,
            'origin_iata' => null,
            'origin_icao' => null,
            'destination_iata' => null,
            'destination_icao' => null,
            'distance_km' => 0.0,
            'distance_nm' => 0.0,
            'flight_hours' => 0.0,
            'operational_hours' => 0.0,
            'billable_hours' => 0.0,
            'cost' => 0.0,
            ...$overrides,
        ];
    }

    private function toCarbon(CarbonInterface|string|null $value): ?\Carbon\Carbon
    {
        if ($value instanceof \Carbon\Carbon) {
            return $value->copy();
        }

        if ($value instanceof CarbonInterface) {
            return \Carbon\Carbon::parse($value->toDateTimeString());
        }

        if (! filled($value)) {
            return null;
        }

        return \Carbon\Carbon::parse((string) $value);
    }
}
