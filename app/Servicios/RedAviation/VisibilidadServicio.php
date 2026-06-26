<?php

namespace App\Servicios\RedAviation;

use App\Modelos\Aeronave;
use App\Modelos\ImagenAeronave;
use App\Modelos\Operacion;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TramoSolicitudVuelo;
use App\Modelos\Usuario;

class VisibilidadServicio
{
    public function solicitudParaCliente(SolicitudVuelo $solicitud, array $options = []): array
    {
        $includeTimeline = (bool) ($options['include_timeline'] ?? true);
        $includeMatches = (bool) ($options['include_matches'] ?? true);
        $summaryOnly = ! $includeTimeline || ! $includeMatches;
        $preferredMatch = $includeMatches ? $this->matchPreferidoParaCliente($solicitud) : null;
        $visibilityPayload = $solicitud->visibility_payload ?? [];
        $assignedAircraft = $solicitud->relationLoaded('assignedAircraft')
            ? $solicitud->assignedAircraft
            : $solicitud->assignedAircraft()->with('images')->first();

        $chat = $solicitud->relationLoaded('chatsProtegidos')
            ? $solicitud->chatsProtegidos->sortByDesc('id')->first()
            : $solicitud->chatsProtegidos()->latest('id')->first();

        $operacion = $solicitud->relationLoaded('latestOperation')
            ? $solicitud->latestOperation
            : ($solicitud->relationLoaded('operaciones')
                ? $solicitud->operaciones->sortByDesc('id')->first()
                : $solicitud->operaciones()->latest('id')->first());
        $reservation = $solicitud->relationLoaded('reservation')
            ? $solicitud->reservation
            : $solicitud->reservation()->with(['contract', 'latestPayment'])->first();
        $latestPayment = $reservation?->relationLoaded('latestPayment')
            ? $reservation->latestPayment
            : ($reservation?->relationLoaded('payments')
                ? $reservation->payments->sortByDesc('id')->first()
                : $reservation?->latestPayment()->first());
        $contract = $reservation?->contract;
        $contractReadyForPayment = in_array(strtolower((string) ($contract?->status ?? '')), ['signed', 'completed'], true)
            || strtolower((string) ($contract?->docusign_status ?? '')) === 'completed'
            || filled($contract?->signed_pdf_path);

        $timeline = $includeTimeline && $operacion
            ? ($operacion->relationLoaded('timeline')
                ? $operacion->timeline->sortByDesc('id')->take(4)->values()
                : $operacion->timeline()->latest('id')->limit(4)->get())
            : collect();

        $matches = $includeMatches
            ? $solicitud->matches->map(fn ($match) => [
                'id' => $match->id,
                'aircraft_id' => $match->aircraft_id,
                'status' => $match->status,
                'estimated_price' => $match->estimated_price,
                'visibility_payload' => $match->visibility_payload,
                'aircraft' => $match->aircraft
                    ? $this->aeronaveVisibleParaCliente($match->aircraft, $solicitud->aircraft_type)
                    : [
                        'model' => null,
                        'capacity' => null,
                        'category' => $solicitud->aircraft_type,
                        'main_image' => null,
                        'images' => [],
                        'amenities' => [],
                    ],
            ])->values()
            : collect();

        return [
            'id' => $solicitud->id,
            'flight_request_id' => $solicitud->id,
            'origin' => $solicitud->origin,
            'destination' => $solicitud->destination,
            'departure_datetime' => $solicitud->departure_datetime,
            'passengers' => $solicitud->passengers,
            'trip_type' => $solicitud->trip_type,
            'aircraft_type' => $solicitud->aircraft_type,
            'assigned_provider_id' => $solicitud->assigned_provider_id,
            'assigned_aircraft_id' => $solicitud->assigned_aircraft_id,
            'aircraft_model' => $assignedAircraft?->model
                ?? $solicitud->assigned_aircraft_model
                ?? $preferredMatch?->aircraft?->model
                ?? $visibilityPayload['aircraft_model']
                ?? null,
            'aircraft_category' => $assignedAircraft?->category
                ?? $preferredMatch?->aircraft?->category
                ?? $visibilityPayload['aircraft_category']
                ?? $solicitud->aircraft_type,
            'aircraft_capacity' => $assignedAircraft?->capacity
                ?? $preferredMatch?->aircraft?->capacity
                ?? $visibilityPayload['aircraft_capacity']
                ?? null,
            'aircraft_image' => $assignedAircraft
                ? $this->aeronaveVisibleParaCliente($assignedAircraft, $solicitud->aircraft_type)['main_image']
                : ($preferredMatch?->aircraft
                    ? $this->aeronaveVisibleParaCliente($preferredMatch->aircraft, $solicitud->aircraft_type)['main_image']
                    : null),
            'quote_total' => $preferredMatch?->estimated_price
                ?? $solicitud->pricing_context['total_amount']
                ?? $solicitud->final_price
                ?? $solicitud->pricing_context['total']
                ?? $solicitud->pricing_context['final_price']
                ?? $visibilityPayload['selected_card_price']
                ?? null,
            'base_price' => $solicitud->base_price,
            'operational_fee' => $solicitud->operational_fee,
            'priority_price' => $solicitud->priority_price,
            'final_price' => $solicitud->final_price,
            'flight_cost' => data_get($solicitud->pricing_context, 'flight_cost'),
            'stripe_fee' => data_get($solicitud->pricing_context, 'stripe_fee'),
            'administrative_fee' => data_get($solicitud->pricing_context, 'administrative_fee'),
            'total_amount' => data_get($solicitud->pricing_context, 'total_amount', $solicitud->final_price),
            'currency' => $solicitud->currency,
            'pricing_context' => $solicitud->pricing_context,
            'visibility_payload' => $visibilityPayload,
            'requirements' => $solicitud->requirements,
            'legs' => $this->visibleLegs($solicitud),
            'notes' => $solicitud->notes,
            'status' => $solicitud->workflow_status ?? $solicitud->status,
            'workflow_status' => $solicitud->workflow_status ?? $solicitud->status,
            'contract_status' => $contract?->status,
            'payment_status' => $solicitud->payment_status
                ?? $latestPayment?->status
                ?? $reservation?->status,
            'reservation_id' => $reservation?->id,
            'summary_only' => $summaryOnly,
            'reservation' => $reservation ? [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'contract_status' => $contract?->status,
                'payment_status' => $solicitud->payment_status ?? $latestPayment?->status,
            ] : null,
            'contract' => $contract ? [
                'id' => $contract->id,
                'status' => $contract->status,
                'docusign_status' => $contract->docusign_status,
                'signed_at' => $contract->signed_at,
                'completed_at' => $contract->completed_at,
                'signed_pdf_url' => filled($contract->signed_pdf_path)
                    ? route('cliente.contratos.pdf-firmado', ['contract' => $contract->id])
                    : null,
                'frontend_state' => [
                    'contract_id' => $contract->id,
                    'ui_status' => strtolower((string) ($contract->docusign_status ?: $contract->status ?: 'generated')),
                    'ready_for_payment' => $contractReadyForPayment,
                ],
            ] : null,
            'chat' => $chat ? [
                'id' => $chat->id,
                'status' => $chat->status,
            ] : null,
            'operation' => $operacion ? [
                'id' => $operacion->id,
                'status' => $operacion->status,
                'timeline' => $timeline->map(fn ($item) => [
                    'id' => $item->id,
                    'status' => $item->status,
                    'title' => $item->title,
                    'description' => $item->description,
                    'created_at' => $item->created_at,
                ])->values(),
            ] : null,
            'matched_options' => $matches,
        ];
    }

    public function solicitudParaOperador(SolicitudVuelo $solicitud): array
    {
        $match = $this->matchPreferidoParaOperador($solicitud);
        $visibilityPayload = $solicitud->visibility_payload ?? [];
        $assignedAircraft = $solicitud->relationLoaded('assignedAircraft')
            ? $solicitud->assignedAircraft
            : $solicitud->assignedAircraft()->first();
        $reservation = $solicitud->relationLoaded('reservation')
            ? $solicitud->reservation
            : $solicitud->reservation()->with(['contract', 'latestPayment'])->first();
        $operation = $solicitud->relationLoaded('latestOperation')
            ? $solicitud->latestOperation
            : ($solicitud->relationLoaded('operaciones')
                ? $solicitud->operaciones->sortByDesc('id')->first()
                : $solicitud->latestOperation()->first());
        $latestPayment = $reservation?->relationLoaded('latestPayment')
            ? $reservation->latestPayment
            : ($reservation?->relationLoaded('payments')
                ? $reservation->payments->sortByDesc('id')->first()
                : $reservation?->latestPayment()->first());
        $operatorStatus = $match?->status === 'rejected'
            ? 'rejected'
            : ($match?->status === 'accepted'
                ? 'accepted'
                : ($solicitud->workflow_status ?? $solicitud->status));

        return [
            'id' => $solicitud->id,
            'origin' => $solicitud->origin,
            'destination' => $solicitud->destination,
            'departure_datetime' => $solicitud->departure_datetime,
            'passengers' => $solicitud->passengers,
            'trip_type' => $solicitud->trip_type,
            'aircraft_type' => $solicitud->aircraft_type,
            'aircraft_model' => $assignedAircraft?->model
                ?? $solicitud->assigned_aircraft_model
                ?? $match?->aircraft?->model
                ?? $visibilityPayload['aircraft_model']
                ?? $match?->visibility_payload['aircraft_model']
                ?? null,
            'aircraft_id' => $solicitud->assigned_aircraft_id ?? $match?->aircraft_id,
            'provider_id' => $solicitud->assigned_provider_id ?? $match?->provider_id,
            'quote_total' => $match?->estimated_price
                ?? $solicitud->pricing_context['total_amount']
                ?? $solicitud->final_price
                ?? $solicitud->pricing_context['total']
                ?? $solicitud->pricing_context['final_price']
                ?? null,
            'base_price' => $solicitud->base_price,
            'operational_fee' => $solicitud->operational_fee,
            'priority_price' => $solicitud->priority_price,
            'final_price' => $solicitud->final_price,
            'flight_cost' => data_get($solicitud->pricing_context, 'flight_cost'),
            'stripe_fee' => data_get($solicitud->pricing_context, 'stripe_fee'),
            'administrative_fee' => data_get($solicitud->pricing_context, 'administrative_fee'),
            'total_amount' => data_get($solicitud->pricing_context, 'total_amount', $solicitud->final_price),
            'currency' => $solicitud->currency,
            'pricing_context' => $solicitud->pricing_context,
            'visibility_payload' => $visibilityPayload,
            'provider_operational_release' => $visibilityPayload['provider_operational_release'] ?? null,
            'response_deadline' => $match?->response_deadline,
            'requirements' => $solicitud->requirements,
            'legs' => $this->visibleLegs($solicitud),
            'status' => $operatorStatus,
            'workflow_status' => $solicitud->workflow_status ?? $solicitud->status,
            'contract_status' => $reservation?->contract?->status,
            'payment_status' => $solicitud->payment_status
                ?? $latestPayment?->status
                ?? $reservation?->status,
            'reservation_id' => $reservation?->id,
            'reservation' => $reservation ? [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'contract_status' => $reservation->contract?->status,
                'payment_status' => $solicitud->payment_status ?? $latestPayment?->status,
            ] : null,
            'operation' => $operation ? [
                'id' => $operation->id,
                'status' => $operation->status,
            ] : null,
            'client' => [
                'display_name' => 'Cliente Red Aviation #'.$solicitud->client_id,
            ],
        ];
    }

    public function solicitudParaAdmin(SolicitudVuelo $solicitud): array
    {
        $preferredMatch = $this->matchPreferidoParaOperador($solicitud);
        $visibilityPayload = $solicitud->visibility_payload ?? [];
        $assignedAircraft = $solicitud->relationLoaded('assignedAircraft')
            ? $solicitud->assignedAircraft
            : $solicitud->assignedAircraft()->first();
        $reservation = $solicitud->relationLoaded('reservation')
            ? $solicitud->reservation
            : $solicitud->reservation()->with(['contract', 'latestPayment'])->first();
        $operation = $solicitud->relationLoaded('latestOperation')
            ? $solicitud->latestOperation
            : $solicitud->latestOperation()->first();
        $timeline = $operation
            ? ($operation->relationLoaded('timeline')
                ? $operation->timeline->sortByDesc('id')->values()
                : $operation->timeline()->latest('id')->get())
            : collect();
        $latestPayment = $reservation?->relationLoaded('latestPayment')
            ? $reservation->latestPayment
            : $reservation?->latestPayment()->first();
        $client = $solicitud->relationLoaded('client') ? $solicitud->client : $solicitud->client()->first();

        return [
            'id' => $solicitud->id,
            'request_id' => $solicitud->id,
            'flight_request_id' => $solicitud->id,
            'reservation_id' => $reservation?->id,
            'origin' => $solicitud->origin,
            'destination' => $solicitud->destination,
            'route' => trim(($solicitud->origin ?? 'N/D').' - '.($solicitud->destination ?? 'N/D')),
            'departure_datetime' => $solicitud->departure_datetime,
            'passengers' => $solicitud->passengers,
            'trip_type' => $solicitud->trip_type,
            'aircraft_type' => $solicitud->aircraft_type,
            'assigned_provider_id' => $solicitud->assigned_provider_id,
            'assigned_aircraft_id' => $solicitud->assigned_aircraft_id,
            'aircraft_model' => $assignedAircraft?->model
                ?? $solicitud->assigned_aircraft_model
                ?? $preferredMatch?->aircraft?->model
                ?? $visibilityPayload['aircraft_model']
                ?? $preferredMatch?->visibility_payload['aircraft_model']
                ?? null,
            'aircraft_id' => $solicitud->assigned_aircraft_id ?? $preferredMatch?->aircraft_id,
            'provider_id' => $solicitud->assigned_provider_id ?? $preferredMatch?->provider_id,
            'quote_total' => $preferredMatch?->estimated_price
                ?? $solicitud->pricing_context['total_amount']
                ?? $solicitud->final_price
                ?? $solicitud->pricing_context['total']
                ?? $solicitud->pricing_context['final_price']
                ?? null,
            'base_price' => $solicitud->base_price,
            'operational_fee' => $solicitud->operational_fee,
            'priority_price' => $solicitud->priority_price,
            'final_price' => $solicitud->final_price,
            'flight_cost' => data_get($solicitud->pricing_context, 'flight_cost'),
            'stripe_fee' => data_get($solicitud->pricing_context, 'stripe_fee'),
            'administrative_fee' => data_get($solicitud->pricing_context, 'administrative_fee'),
            'total_amount' => data_get($solicitud->pricing_context, 'total_amount', $solicitud->final_price),
            'currency' => $solicitud->currency,
            'pricing_context' => $solicitud->pricing_context,
            'visibility_payload' => $visibilityPayload,
            'provider_operational_release' => $visibilityPayload['provider_operational_release'] ?? null,
            'operational_status' => $visibilityPayload['operational_status'] ?? null,
            'aircraft_confirmed' => (bool) ($visibilityPayload['aircraft_confirmed'] ?? false),
            'crew_confirmed' => (bool) ($visibilityPayload['crew_confirmed'] ?? false),
            'operational_ready' => (bool) ($visibilityPayload['operational_ready'] ?? false),
            'requirements' => $solicitud->requirements,
            'legs' => $this->visibleLegs($solicitud),
            'status' => $solicitud->status,
            'workflow_status' => $solicitud->workflow_status ?? $solicitud->status,
            'contract_status' => $reservation?->contract?->status,
            'payment_status' => $solicitud->payment_status
                ?? $latestPayment?->status
                ?? $reservation?->status,
            'reservation' => $reservation ? [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'contract_status' => $reservation->contract?->status,
                'payment_status' => $solicitud->payment_status ?? $latestPayment?->status,
            ] : null,
            'crew_id' => $operation?->sobrecargo_user_id,
            'sobrecargo_id' => $operation?->sobrecargo_user_id,
            'crew_name' => $operation?->sobrecargo?->name,
            'sobrecargo' => $operation?->sobrecargo ? [
                'id' => $operation->sobrecargo->id,
                'name' => $operation->sobrecargo->name,
            ] : null,
            'crew_status' => $operation?->crew_status,
            'operation' => $operation ? [
                'id' => $operation->id,
                'status' => $operation->status,
                'sobrecargo_user_id' => $operation->sobrecargo_user_id,
                'crew_status' => $operation->crew_status,
                'sobrecargo' => $operation->sobrecargo ? [
                    'id' => $operation->sobrecargo->id,
                    'name' => $operation->sobrecargo->name,
                ] : null,
                'timeline' => $timeline->map(fn ($item) => [
                    'id' => $item->id,
                    'status' => $item->status,
                    'title' => $item->title,
                    'description' => $item->description,
                    'created_at' => $item->created_at,
                ])->values(),
            ] : null,
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'company' => $client->company_name ?? null,
            ] : null,
            'client_name' => $client?->name,
            'company_name' => $client->company_name ?? null,
            'matches' => $solicitud->matches->map(fn ($match) => [
                'id' => $match->id,
                'provider_id' => $match->provider_id,
                'aircraft_id' => $match->aircraft_id,
                'status' => $match->status,
                'estimated_price' => $match->estimated_price,
                'aircraft' => $match->aircraft ? [
                    'id' => $match->aircraft->id,
                    'model' => $match->aircraft->model,
                    'category' => $match->aircraft->category,
                    'capacity' => $match->aircraft->capacity,
                ] : null,
            ])->values(),
        ];
    }

    public function operacionParaSobrecargo(Operacion $operacion): array
    {
        $solicitud = $operacion->solicitudVuelo;

        return [
            'id' => $operacion->id,
            'status' => $operacion->status,
            'crew_status' => $operacion->crew_status,
            'crew_confirmed_at' => $operacion->crew_confirmed_at,
            'crew_decline_reason' => $operacion->crew_decline_reason,
            'crew_notes' => $operacion->crew_notes,
            'crew_checkin_at' => $operacion->crew_checkin_at,
            'crew_service_started_at' => $operacion->crew_service_started_at,
            'crew_service_completed_at' => $operacion->crew_service_completed_at,
            'briefing' => [
                'origen' => $solicitud?->origin,
                'destino' => $solicitud?->destination,
                'salida' => $solicitud?->departure_datetime,
                'pasajeros_autorizados' => $solicitud?->passengers,
            ],
            'timeline' => $operacion->timeline()->latest()->get(),
        ];
    }

    public function usuarioVisible(Usuario $usuario): array
    {
        return [
            'id' => $usuario->id,
            'name' => $usuario->name,
            'role' => $usuario->effectiveRole(),
            'status' => $usuario->status,
            'contact_strikes' => $usuario->contact_strikes,
        ];
    }

    private function aeronaveVisibleParaCliente(Aeronave $aircraft, ?string $fallbackCategory = null): array
    {
        $loadedImages = $aircraft->relationLoaded('images') ? $aircraft->images : collect();
        $sortedImages = $loadedImages
            ->filter(fn (ImagenAeronave $image) => filled($image->image_url))
            ->sortBy([
                ['is_main', 'desc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $visibleImages = $sortedImages
            ->where('visible_to_client', true)
            ->values();

        if ($visibleImages->isEmpty()) {
            $visibleImages = $sortedImages;
        }

        $mainImage = $visibleImages->firstWhere('is_main', true)?->image_url
            ?? $visibleImages->first()?->image_url;

        return [
            'model' => $aircraft->model,
            'capacity' => $aircraft->capacity,
            'category' => $aircraft->category ?? $fallbackCategory,
            'main_image' => $mainImage,
            'images' => $visibleImages->map(fn (ImagenAeronave $image) => [
                'id' => $image->id,
                'kind' => $image->kind,
                'title' => $image->title,
                'image_url' => $image->image_url,
                'is_main' => $image->is_main,
            ])->values(),
            'amenities' => $visibleImages
                ->whereIn('kind', ['amenities', 'cabin', 'seats'])
                ->pluck('title')
                ->filter()
                ->values(),
        ];
    }

    private function matchPreferidoParaOperador(SolicitudVuelo $solicitud)
    {
        $matches = $solicitud->relationLoaded('matches')
            ? $solicitud->matches->values()
            : $solicitud->matches()->with('aircraft')->get()->values();

        return $matches->first(fn ($match) => $match->status === 'accepted')
            ?? $matches->first(fn ($match) => in_array($match->status, ['sent_to_provider', 'pending'], true))
            ?? $matches->first();
    }

    private function matchPreferidoParaCliente(SolicitudVuelo $solicitud)
    {
        $matches = $solicitud->relationLoaded('matches')
            ? $solicitud->matches->values()
            : $solicitud->matches()->with('aircraft')->get()->values();

        if ($solicitud->assigned_aircraft_id) {
            $assignedMatch = $matches->first(
                fn ($match) => (int) $match->aircraft_id === (int) $solicitud->assigned_aircraft_id
            );
            if ($assignedMatch) {
                return $assignedMatch;
            }
        }

        return $this->matchPreferidoParaOperador($solicitud);
    }

    private function visibleLegs(SolicitudVuelo $solicitud)
    {
        $legs = $solicitud->relationLoaded('legs')
            ? $solicitud->legs->sortBy('leg_order')->values()
            : $solicitud->legs()->orderBy('leg_order')->get();

        return $legs->map(fn (TramoSolicitudVuelo $leg) => [
            'id' => $leg->id,
            'leg_order' => $leg->leg_order,
            'origin' => $leg->origin,
            'destination' => $leg->destination,
            'departure_datetime' => $leg->departure_datetime,
            'arrival_datetime' => $leg->arrival_datetime,
            'passengers' => $leg->passengers,
            'distance_km' => $leg->distance_km,
        ])->values();
    }
}
