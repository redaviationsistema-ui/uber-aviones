<?php

namespace App\Servicios\RedAviation;

use App\Dominio\Sobrecargo\CrewAssignmentStatus;
use App\Modelos\ChecklistItem;
use App\Modelos\ConfiguracionSistema;
use App\Modelos\Operacion;
use App\Modelos\SolicitudVuelo;

class FlightBriefService
{
    public function buildForFlightRequest(SolicitudVuelo $flightRequest): array
    {
        $flightRequest->loadMissing([
            'originAirport:id,icao,iata,name,city',
            'destinationAirport:id,icao,iata,name,city',
            'legs:id,flight_request_id,leg_order,arrival_datetime',
            'assignedAircraft:id,model,registration',
            'assignedAircraft.images:id,aircraft_id,image_url,is_main,sort_order,visible_to_client',
            'reservation:id,flight_request_id,status',
            'reservation.latestPayment',
            'latestOperation',
            'latestOperation.sobrecargo:id,name',
            'latestOperation.latestCrewAssignment',
            'latestOperation.checklists:id,operation_id,sobrecargo_user_id,type,status,submitted_at',
            'latestOperation.checklists.items:id,checklist_id,status,is_required,is_completed',
        ]);

        $reservation = $flightRequest->reservation;
        $payment = $reservation?->latestPayment;
        $operation = $flightRequest->latestOperation;
        $paymentConfirmed = $this->paymentIsConfirmed($flightRequest, $reservation?->status, $payment?->status);
        $checklist = $this->summarizeChecklists($operation);
        $crew = $this->buildCrew($operation);
        $departure = $this->buildAirport($flightRequest->originAirport, $flightRequest->origin);
        $arrival = $this->buildAirport($flightRequest->destinationAirport, $flightRequest->destination);
        $aircraft = $this->buildAircraft($flightRequest);

        return [
            'flight_request_id' => $flightRequest->id,
            'visible' => $paymentConfirmed,
            'payment' => [
                'confirmed' => $paymentConfirmed,
                'status' => $flightRequest->payment_status ?: ($payment?->status ?: $reservation?->status),
                'paid_at' => $payment?->paid_at?->toISOString(),
            ],
            'flight' => [
                'origin' => $flightRequest->origin,
                'destination' => $flightRequest->destination,
                'status' => $flightRequest->status,
                'date' => $flightRequest->departure_datetime?->toDateString(),
                'time' => $flightRequest->departure_datetime?->format('H:i'),
                'aircraft' => $aircraft['model'] ?: $flightRequest->assigned_aircraft_model,
                'departure_datetime' => $flightRequest->departure_datetime?->toISOString(),
                'arrival_datetime' => $this->resolveArrivalDateTime($flightRequest),
                'duration_hours' => $this->resolveOfficialDurationHours($flightRequest),
            ],
            'departure' => $departure,
            'arrival' => $arrival,
            'aircraft' => $aircraft,
            'passengers' => [
                'count' => $flightRequest->passengers,
            ],
            'services' => $this->buildRequestedServices($flightRequest),
            'presentation' => $this->buildPresentation($flightRequest, $departure),
            'support' => $this->buildCorporateSupport(),
            'provider' => [
                'assigned' => $flightRequest->assigned_provider_id !== null,
                'visible_name' => null,
                'status' => null,
            ],
            'operation' => [
                'id' => $operation?->id,
                'status' => $operation?->status,
                'crew_status' => $operation?->crew_status,
            ],
            'crew' => $crew,
            'checklist' => $checklist,
            'readiness' => $this->resolveReadiness($paymentConfirmed, $operation, $crew, $checklist),
        ];
    }

    private function buildAirport($airport, ?string $fallbackCode): array
    {
        return [
            'code' => $airport?->icao ?: $airport?->iata ?: $fallbackCode,
            'airport_name' => $airport?->name,
            'city' => $airport?->city,
        ];
    }

    private function buildAircraft(SolicitudVuelo $flightRequest): array
    {
        $aircraft = $flightRequest->assignedAircraft;
        $image = $aircraft?->images
            ?->filter(fn ($image) => $image->visible_to_client && filled($image->image_url))
            ->sortBy([
                ['is_main', 'desc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->first();

        return [
            'model' => $aircraft?->model ?: $flightRequest->assigned_aircraft_model,
            'registration' => $aircraft?->registration,
            'image_url' => $image?->image_url,
        ];
    }

    private function resolveArrivalDateTime(SolicitudVuelo $flightRequest): ?string
    {
        return $flightRequest->legs
            ->sortBy([
                ['leg_order', 'asc'],
                ['id', 'asc'],
            ])
            ->last()
            ?->arrival_datetime
            ?->toISOString();
    }

    private function resolveOfficialDurationHours(SolicitudVuelo $flightRequest): ?float
    {
        $pricingContext = is_array($flightRequest->pricing_context) ? $flightRequest->pricing_context : [];
        $duration = $pricingContext['client_display_flight_hours']
            ?? $pricingContext['display_route_hours']
            ?? null;

        return is_numeric($duration) ? (float) $duration : null;
    }

    private function buildRequestedServices(SolicitudVuelo $flightRequest): array
    {
        $pricingContext = is_array($flightRequest->pricing_context) ? $flightRequest->pricing_context : [];
        $extras = is_array($pricingContext['extras'] ?? null) ? $pricingContext['extras'] : [];
        $specialBaggage = $this->requestedServiceDescription($extras['special_baggage'] ?? null);

        return [
            'catering' => [
                'requested' => $this->isServiceRequested($extras['catering'] ?? null),
            ],
            'special_baggage' => [
                'requested' => $specialBaggage !== null,
                'description' => $specialBaggage,
            ],
            'ground_transport' => [
                'requested' => $this->isServiceRequested($extras['ground_transport'] ?? null),
            ],
        ];
    }

    private function buildPresentation(SolicitudVuelo $flightRequest, array $departure): array
    {
        $payload = is_array($flightRequest->visibility_payload) ? $flightRequest->visibility_payload : [];
        $briefing = is_array($payload['briefing'] ?? null) ? $payload['briefing'] : [];
        $location = $this->nullableText($payload['presentation_location'] ?? $payload['presentation_place'] ?? $briefing['lugar_presentacion'] ?? null);
        $presentationDateTime = $this->presentationDateTime(
            $payload['presentation_datetime'] ?? $payload['presentation_time'] ?? $briefing['hora_presentacion'] ?? null,
            $flightRequest
        );

        return [
            'airport_code' => $departure['code'],
            'airport_name' => $departure['airport_name'],
            'city' => $departure['city'],
            'location_name' => $location,
            'address' => $this->nullableText($payload['presentation_address'] ?? null),
            'presentation_datetime' => $presentationDateTime,
            'instructions' => $this->nullableText($payload['presentation_instructions'] ?? null),
            'maps_url' => $this->nullableText($payload['presentation_maps_url'] ?? null),
            'is_complete' => $location !== null && $presentationDateTime !== null,
        ];
    }

    private function presentationDateTime(mixed $value, SolicitudVuelo $flightRequest): ?string
    {
        $text = $this->nullableText($value);
        if ($text === null) {
            return null;
        }

        if (preg_match('/^\d{1,2}:\d{2}$/', $text) && $flightRequest->departure_datetime) {
            return $flightRequest->departure_datetime->copy()->setTimeFromTimeString($text)->toISOString();
        }

        try {
            return \Carbon\Carbon::parse($text)->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildCorporateSupport(): array
    {
        $setting = ConfiguracionSistema::query()->where('key', 'support_contact')->first();
        $support = is_array($setting?->value) ? $setting->value : [];

        return [
            'name' => $this->nullableText($support['name'] ?? null),
            'phone' => $this->nullableText($support['phone'] ?? null),
            'whatsapp' => $this->nullableText($support['whatsapp'] ?? null),
            'email' => $this->nullableText($support['email'] ?? null),
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function isServiceRequested(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_scalar($value)) {
            return false;
        }

        $normalized = strtolower(trim((string) $value));

        return ! in_array($normalized, ['', '0', 'false', 'no', 'none', 'null'], true);
    }

    private function requestedServiceDescription(mixed $value): ?string
    {
        if (! $this->isServiceRequested($value) || ! is_scalar($value)) {
            return null;
        }

        return trim((string) $value);
    }

    private function paymentIsConfirmed(SolicitudVuelo $flightRequest, ?string $reservationStatus, ?string $paymentStatus): bool
    {
        return strtolower(trim((string) $flightRequest->payment_status)) === 'paid'
            || strtolower(trim((string) $paymentStatus)) === 'paid'
            || in_array(strtolower(trim((string) $reservationStatus)), ['paid', 'confirmed'], true);
    }

    private function buildCrew(?Operacion $operation): array
    {
        $assignment = $operation?->latestCrewAssignment;
        $assignmentStatus = $assignment ? CrewAssignmentStatus::normalize($assignment->status) : null;
        $operationCrewStatus = $operation ? CrewAssignmentStatus::normalize($operation->crew_status) : null;
        $assigned = $operation?->sobrecargo_user_id !== null || $assignment !== null;

        return [
            'required' => null,
            'assigned' => $assigned,
            'confirmed' => $assignmentStatus === CrewAssignmentStatus::CONFIRMED || $operation?->crew_confirmed_at !== null,
            'status' => $operationCrewStatus ?: $assignmentStatus,
            'visible_name' => $operation?->sobrecargo?->name,
        ];
    }

    private function summarizeChecklists(?Operacion $operation): array
    {
        $checklists = $operation?->checklists ?? collect();
        $items = $checklists->flatMap(fn ($checklist) => $checklist->items);
        $total = $items->count();
        $requiredItems = $items->filter(fn (ChecklistItem $item) => (bool) $item->is_required);
        $completedItems = $items->filter(fn (ChecklistItem $item) => $this->itemIsCompleted($item));
        $requiredCompleted = $requiredItems->filter(fn (ChecklistItem $item) => $this->itemIsCompleted($item))->count();
        $latestSubmission = $checklists
            ->filter(fn ($checklist) => $checklist->submitted_at !== null)
            ->sortByDesc('submitted_at')
            ->first();

        return [
            'exists' => $checklists->isNotEmpty(),
            'completed' => $completedItems->count(),
            'total' => $total,
            'required_completed' => $requiredCompleted,
            'required_total' => $requiredItems->count(),
            'percentage' => $total > 0 ? (int) round(($completedItems->count() / $total) * 100) : 0,
            'is_complete' => $checklists->isNotEmpty() && $total > 0 && $requiredCompleted === $requiredItems->count(),
            'submitted_at' => $latestSubmission?->submitted_at?->toISOString(),
        ];
    }

    private function itemIsCompleted(ChecklistItem $item): bool
    {
        return in_array($item->status, ['completed', 'not_applicable'], true);
    }

    private function resolveReadiness(bool $paymentConfirmed, ?Operacion $operation, array $crew, array $checklist): array
    {
        if (! $paymentConfirmed) {
            return ['ready' => false, 'code' => 'payment_pending', 'label' => 'Pago pendiente de confirmación.'];
        }
        if (! $operation) {
            return ['ready' => false, 'code' => 'operation_pending', 'label' => 'La operación aún está pendiente de asignación.'];
        }
        if (! $crew['assigned']) {
            return ['ready' => false, 'code' => 'crew_unassigned', 'label' => 'La tripulación aún no está asignada.'];
        }
        if (! $crew['confirmed']) {
            return ['ready' => false, 'code' => 'crew_pending_confirmation', 'label' => 'La tripulación está pendiente de confirmación.'];
        }
        if (! $checklist['exists']) {
            return ['ready' => false, 'code' => 'checklist_not_started', 'label' => 'El checklist operativo aún no está disponible.'];
        }
        if (! $checklist['is_complete']) {
            return ['ready' => false, 'code' => 'checklist_in_progress', 'label' => 'El checklist operativo sigue en progreso.'];
        }

        return ['ready' => true, 'code' => 'ready', 'label' => 'La operación está lista para seguimiento.'];
    }
}
