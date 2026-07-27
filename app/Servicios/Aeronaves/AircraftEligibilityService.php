<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\Aeronave;
use App\Servicios\Vuelos\FlightRouteService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

final class AircraftEligibilityService
{
    public const RULE_VERSION = 'aircraft_eligibility_v1';

    private const ACTIVE_AIRCRAFT_STATUSES = [
        'active', 'activa', 'activo', 'trial_active', 'approved', 'aprobada', 'available', 'disponible',
    ];

    private const NON_OPERATIONAL_STATUSES = [
        'inactive', 'inactiva', 'disabled', 'deshabilitada', 'suspended', 'suspendida', 'blocked', 'bloqueada',
        'maintenance', 'mantenimiento', 'out_of_service', 'fuera_de_servicio', 'grounded',
    ];

    public function __construct(
        private readonly AircraftAvailabilityService $availabilityService,
        private readonly AircraftStateService $aircraftStateService,
        private readonly FlightRouteService $flightRouteService,
    ) {}

    public function evaluate(Aeronave|int $aircraft, array $context): array
    {
        $resolved = $aircraft instanceof Aeronave
            ? $aircraft->loadMissing(['provider', 'documents', 'availability', 'availabilityBlocks', 'baseAirport'])
            : Aeronave::query()
                ->with(['provider', 'documents', 'availability', 'availabilityBlocks', 'baseAirport'])
                ->find($aircraft);
        $reasons = [];
        $warnings = [];
        $rules = [];

        if (! $resolved) {
            return $this->result(null, $context, ['AIRCRAFT_NOT_FOUND'], [], ['aircraft_exists' => false]);
        }

        $provider = $resolved->provider;
        $status = $this->normalize($resolved->status);
        $operationalStatus = $this->normalize($resolved->operational_status);
        $rules['aircraft_status'] = $status;
        $rules['aircraft_operational_status'] = $operationalStatus;

        if (
            ! in_array($status, self::ACTIVE_AIRCRAFT_STATUSES, true)
            || in_array($status, self::NON_OPERATIONAL_STATUSES, true)
            || in_array($operationalStatus, self::NON_OPERATIONAL_STATUSES, true)
            || ($resolved->is_active !== null && ! $resolved->is_active)
        ) {
            $reasons[] = in_array($status, ['maintenance', 'mantenimiento'], true)
                || in_array($operationalStatus, ['maintenance', 'mantenimiento'], true)
                    ? 'AIRCRAFT_IN_MAINTENANCE'
                    : 'AIRCRAFT_NOT_ACTIVE';
        }

        $providerStatus = $this->normalize($provider?->status);
        $providerOperatorStatus = $this->normalize($provider?->operator_status);
        if (! $provider) {
            $reasons[] = 'PROVIDER_NOT_FOUND';
        } elseif (
            ! $provider->isApprovedForOperations()
            || in_array($providerStatus, self::NON_OPERATIONAL_STATUSES, true)
            || in_array($providerOperatorStatus, self::NON_OPERATIONAL_STATUSES, true)
        ) {
            $reasons[] = 'PROVIDER_NOT_APPROVED';
        }
        $rules['provider_approval_status'] = $provider?->resolvedApprovalStatus();
        $rules['provider_status'] = $providerStatus;
        $rules['provider_operator_status'] = $providerOperatorStatus;

        $passengers = (int) ($context['passengers'] ?? 0);
        $capacity = (int) ($resolved->capacity ?? 0);
        if ($passengers <= 0 || $capacity <= 0) {
            $reasons[] = 'INVALID_AIRCRAFT_DATA';
        } elseif ($passengers > $capacity) {
            $reasons[] = 'INSUFFICIENT_CAPACITY';
        }
        $rules['capacity'] = ['requested' => $passengers, 'available' => $capacity];

        $rangeKm = (float) ($resolved->range_km ?? 0);
        $rangeReserveFactor = max(1.0, (float) ($context['range_reserve_factor'] ?? 1.0));
        try {
            $legs = $this->rangeLegs($resolved, $context);
        } catch (HttpResponseException) {
            $legs = data_get($context, 'route.legs', []);
            $reasons[] = 'INVALID_AIRCRAFT_DATA';
            $warnings[] = 'AIRCRAFT_BASE_AIRPORT_UNRESOLVED';
        }
        $maxRequiredRangeKm = (float) collect($legs)->max(
            fn (array $leg) => (float) ($leg['distance_km'] ?? 0) * $rangeReserveFactor
        );
        if ($rangeKm <= 0) {
            $reasons[] = 'INVALID_AIRCRAFT_DATA';
        } elseif ($maxRequiredRangeKm > $rangeKm) {
            $reasons[] = 'INSUFFICIENT_RANGE';
        }
        $rules['range'] = [
            'aircraft_km' => $rangeKm,
            'maximum_required_km' => $maxRequiredRangeKm,
            'reserve_factor' => $rangeReserveFactor,
        ];

        $requestedCategory = $this->normalize($context['requested_category'] ?? $context['preference'] ?? '');
        $categoryRequired = filter_var($context['category_required'] ?? false, FILTER_VALIDATE_BOOL);
        if ($requestedCategory !== '' && $requestedCategory !== $this->normalize($resolved->category)) {
            if ($categoryRequired) {
                $reasons[] = 'CATEGORY_MISMATCH';
            } else {
                $warnings[] = 'CATEGORY_PREFERENCE_MISMATCH';
            }
        }

        $requiredAmenities = collect($context['required_amenities'] ?? [])
            ->map(fn ($value) => $this->normalize($value))
            ->filter();
        $rawAmenities = $resolved->amenities;
        if (is_string($rawAmenities)) {
            $rawAmenities = json_decode($rawAmenities, true);
        }
        $aircraftAmenities = collect(is_array($rawAmenities) ? $rawAmenities : [])
            ->map(fn ($value) => $this->normalize(is_array($value) ? ($value['name'] ?? $value['code'] ?? '') : $value));
        if ($requiredAmenities->diff($aircraftAmenities)->isNotEmpty()) {
            $reasons[] = 'REQUIRED_AMENITY_MISSING';
        }

        $documentState = $this->aircraftStateService->evaluateDocuments($resolved);
        $documentFiles = (int) ($documentState['files_count'] ?? 0);
        if ($documentFiles === 0) {
            $warnings[] = 'DOCUMENTS_NOT_DIGITIZED';
        } else {
            if ((int) ($documentState['expired'] ?? 0) > 0) {
                $reasons[] = 'DOCUMENT_EXPIRED';
            }
            if ((int) ($documentState['rejected'] ?? 0) > 0) {
                $reasons[] = 'DOCUMENT_NOT_APPROVED';
            }
            if ((int) ($documentState['pending'] ?? 0) > 0 || (int) ($documentState['missing'] ?? 0) > 0) {
                $reasons[] = 'DOCUMENT_NOT_APPROVED';
            }
        }
        $rules['documents'] = [
            'required_keys' => collect($documentState['requirements'] ?? [])->pluck('key')->values()->all(),
            'files_count' => $documentFiles,
            'valid' => (bool) ($documentState['valid'] ?? false),
            'legacy_missing_policy' => 'warning_until_global_enforcement',
        ];

        $start = $context['requested_start'] ?? null;
        $end = $context['requested_end'] ?? null;
        $temporallyAvailable = true;
        if (array_key_exists('temporally_available', $context)) {
            $temporallyAvailable = (bool) $context['temporally_available'];
            if (! $temporallyAvailable) {
                $reasons[] = 'AIRCRAFT_NOT_AVAILABLE';
            }
        } elseif ($start && $end) {
            $temporallyAvailable = ! $this->availabilityService->aircraftHasConflictExcluding(
                (int) $resolved->id,
                $start,
                $end,
                $context['reservation_id'] ?? null,
                $context['quote_id'] ?? null,
            );
            if (! $temporallyAvailable) {
                $reasons[] = 'AIRCRAFT_NOT_AVAILABLE';
            }
        }
        $rules['availability_checked'] = array_key_exists('temporally_available', $context) || (bool) ($start && $end);

        return $this->result($resolved, $context, $reasons, $warnings, $rules, $temporallyAvailable);
    }

    private function result(
        ?Aeronave $aircraft,
        array $context,
        array $reasons,
        array $warnings,
        array $rules,
        bool $temporallyAvailable = true,
    ): array {
        $reasonCodes = array_values(array_unique($reasons));
        $commercialReasons = array_intersect($reasonCodes, [
            'AIRCRAFT_NOT_FOUND', 'AIRCRAFT_NOT_ACTIVE', 'PROVIDER_NOT_FOUND', 'PROVIDER_NOT_APPROVED',
            'INSUFFICIENT_CAPACITY', 'INSUFFICIENT_RANGE', 'CATEGORY_MISMATCH', 'REQUIRED_AMENITY_MISSING',
            'INVALID_AIRCRAFT_DATA',
        ]);
        $operationalReasons = array_intersect($reasonCodes, [
            'AIRCRAFT_IN_MAINTENANCE', 'DOCUMENT_EXPIRED', 'DOCUMENT_NOT_APPROVED',
        ]);

        return [
            'eligible' => $reasonCodes === [],
            'commercially_eligible' => $commercialReasons === [],
            'operationally_eligible' => $operationalReasons === [],
            'temporally_available' => $temporallyAvailable && ! in_array('AIRCRAFT_NOT_AVAILABLE', $reasonCodes, true),
            'reason_code' => $reasonCodes[0] ?? null,
            'reason_codes' => $reasonCodes,
            'reasons' => array_map(fn (string $code) => $this->reason($code), $reasonCodes),
            'warnings' => array_values(array_unique($warnings)),
            'evaluated_rules' => $rules,
            'rule_version' => self::RULE_VERSION,
            'aircraft_id' => $aircraft?->id,
            'provider_id' => $aircraft?->provider_id,
            'evaluated_at' => Carbon::parse($context['evaluated_at'] ?? Carbon::now())->toISOString(),
        ];
    }

    private function rangeLegs(Aeronave $aircraft, array $context): array
    {
        $route = $context['route'] ?? [];
        $legs = is_array($route['legs'] ?? null) ? $route['legs'] : [];
        $firstOrigin = data_get($legs, '0.origin');
        $lastDestination = data_get($legs, (count($legs) - 1).'.destination');
        $base = $aircraft->resolvedBaseAirportCode();

        foreach ([[$base, $firstOrigin, 'repositioning'], [$lastDestination, $base, 'return_to_base']] as [$origin, $destination, $kind]) {
            if (! filled($origin) || ! filled($destination) || $this->airportCodesMatch($origin, $destination)) {
                continue;
            }

            $operationalRoute = $this->flightRouteService->buildCanonicalRoute([
                'origin' => $origin,
                'destination' => $destination,
                'trip_type' => 'one_way',
            ]);
            foreach ($operationalRoute['legs'] as $leg) {
                $legs[] = [...$leg, 'operational_leg_type' => $kind];
            }
        }

        return $legs;
    }

    private function airportCodesMatch(string $left, string $right): bool
    {
        if (strtoupper(trim($left)) === strtoupper(trim($right))) {
            return true;
        }

        return $this->flightRouteService->referencesMatch($left, $right);
    }

    private function normalize(mixed $value): string
    {
        return Str::of((string) ($value ?? ''))->trim()->lower()->ascii()->replaceMatches('/[\s-]+/', '_')->value();
    }

    private function reason(string $code): string
    {
        return match ($code) {
            'AIRCRAFT_NOT_FOUND' => 'La aeronave no existe.',
            'AIRCRAFT_NOT_ACTIVE' => 'La aeronave no está activa.',
            'PROVIDER_NOT_FOUND' => 'La aeronave no tiene proveedor válido.',
            'PROVIDER_NOT_APPROVED' => 'El proveedor no está aprobado para operar.',
            'INSUFFICIENT_CAPACITY' => 'La aeronave no tiene capacidad suficiente.',
            'INSUFFICIENT_RANGE' => 'La aeronave no tiene alcance suficiente para todos los tramos.',
            'DOCUMENT_EXPIRED' => 'La aeronave tiene documentación obligatoria vencida.',
            'DOCUMENT_NOT_APPROVED' => 'La documentación obligatoria de la aeronave no está aprobada.',
            'AIRCRAFT_IN_MAINTENANCE' => 'La aeronave se encuentra en mantenimiento.',
            'AIRCRAFT_NOT_AVAILABLE' => 'La aeronave no está disponible para el horario solicitado.',
            'CATEGORY_MISMATCH' => 'La aeronave no cumple la categoría obligatoria solicitada.',
            'REQUIRED_AMENITY_MISSING' => 'La aeronave no cumple una amenidad obligatoria.',
            default => 'La aeronave contiene datos operativos inválidos.',
        };
    }
}
