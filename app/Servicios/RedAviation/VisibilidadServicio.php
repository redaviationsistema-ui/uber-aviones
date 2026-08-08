<?php

namespace App\Servicios\RedAviation;

use App\Http\Resources\RedAviation\OfficialQuotePricingResource;
use App\Modelos\Aeronave;
use App\Modelos\ImagenAeronave;
use App\Modelos\Operacion;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TramoSolicitudVuelo;
use App\Modelos\Usuario;
use Illuminate\Support\Facades\Log;

class VisibilidadServicio
{
    public function solicitudParaCliente(SolicitudVuelo $solicitud, array $options = []): array
    {
        $includeTimeline = (bool) ($options['include_timeline'] ?? true);
        $includeMatches = (bool) ($options['include_matches'] ?? true);
        $skipReservationLookup = (bool) ($options['skip_reservation_lookup'] ?? false);
        $summaryOnly = ! $includeTimeline || ! $includeMatches;
        $preferredMatch = $includeMatches ? $this->matchPreferidoParaCliente($solicitud) : null;
        $visibilityPayload = $solicitud->visibility_payload ?? [];
        $assignedAircraft = $this->resolveAssignedAircraft($solicitud, true);

        $chat = $solicitud->relationLoaded('chatsProtegidos')
            ? $solicitud->chatsProtegidos->sortByDesc('id')->first()
            : $solicitud->chatsProtegidos()->latest('id')->first();

        $operacion = $solicitud->relationLoaded('latestOperation')
            ? $solicitud->latestOperation
            : ($solicitud->relationLoaded('operaciones')
                ? $solicitud->operaciones->sortByDesc('id')->first()
                : $solicitud->operaciones()->latest('id')->first());
        $reservation = $skipReservationLookup ? null : $this->resolveReservation($solicitud);
        $latestPayment = $this->resolveLatestPayment($reservation);
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
        $acceptedQuote = $solicitud->relationLoaded('quotes')
            ? $solicitud->quotes->where('status', 'accepted')->sortByDesc('id')->first()
            : $solicitud->quotes()->where('status', 'accepted')->latest('id')->first();
        $visibleQuoteTotal = $this->resolveVisibleQuoteTotal(
            $acceptedQuote?->total,
            data_get($solicitud->pricing_context, 'total_amount'),
            $solicitud->final_price,
            $preferredMatch?->estimated_price,
            $visibilityPayload['selected_card_price'] ?? null,
        );
        $this->warnWhenQuoteTotalsDiverge($solicitud, [
            'accepted_quote.total' => $acceptedQuote?->total,
            'pricing_context.total_amount' => data_get($solicitud->pricing_context, 'total_amount'),
            'final_price' => $solicitud->final_price,
            'preferred_match.estimated_price' => $preferredMatch?->estimated_price,
            'visibility_payload.selected_card_price' => $visibilityPayload['selected_card_price'] ?? null,
        ]);

        Log::info('Client flight request payload serialized', [
            'flight_request_id' => $solicitud->id,
            'assigned_aircraft_id' => $solicitud->assigned_aircraft_id,
            'assigned_provider_id' => $solicitud->assigned_provider_id,
            'preferred_match_id' => $preferredMatch?->id,
            'preferred_match_estimated_price' => $preferredMatch?->estimated_price,
            'final_price' => $solicitud->final_price,
            'pricing_context_total_amount' => data_get($solicitud->pricing_context, 'total_amount'),
            'pricing_context_total' => data_get($solicitud->pricing_context, 'total'),
            'visibility_selected_card_price' => $visibilityPayload['selected_card_price'] ?? null,
            'accepted_quote_id' => $acceptedQuote?->id,
            'accepted_quote_total' => $acceptedQuote?->total,
            'currency' => $solicitud->currency,
        ]);
        $pricingPayload = $this->officialPricingPayload(
            is_array($solicitud->pricing_context) ? $solicitud->pricing_context : [],
            $solicitud->currency,
        );

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
            'quote_total' => $visibleQuoteTotal,
            'base_price' => $solicitud->base_price,
            'operational_fee' => $solicitud->operational_fee,
            'priority_price' => $solicitud->priority_price,
            'final_price' => $solicitud->final_price,
            ...$pricingPayload,
            'currency' => $solicitud->currency,
            'pricing_context' => $solicitud->pricing_context,
            'visibility_payload' => $visibilityPayload,
            'requirements' => $solicitud->requirements,
            'legs' => $this->visibleLegs($solicitud),
            'notes' => $solicitud->notes,
            'status' => $solicitud->workflow_status ?? $solicitud->status,
            'workflow_status' => $solicitud->workflow_status ?? $solicitud->status,
            'booking_status' => $solicitud->payment_status === 'paid' ? 'confirmed' : $solicitud->status,
            'reservation_status' => $reservation?->status,
            'contract_status' => $contract?->status,
            'payment_status' => $solicitud->payment_status
                ?? $latestPayment?->status
                ?? $reservation?->status,
            'stripe_checkout_session_id' => $solicitud->stripe_checkout_session_id ?? $latestPayment?->stripe_checkout_session_id,
            'stripe_payment_intent_id' => $solicitud->stripe_payment_intent_id ?? $latestPayment?->stripe_payment_intent_id,
            'reservation_id' => $reservation?->id,
            'accepted_quote' => $acceptedQuote ? [
                'id' => $acceptedQuote->id,
                'quote_id' => $acceptedQuote->id,
                'aircraft_id' => $acceptedQuote->aircraft_id,
                'provider_id' => $acceptedQuote->provider_id,
                'status' => $acceptedQuote->status,
                'total' => $acceptedQuote->total,
                'currency' => $acceptedQuote->currency,
                'expires_at' => $acceptedQuote->expires_at,
            ] : null,
            'summary_only' => $summaryOnly,
            'reservation' => $reservation ? [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'booking_status' => $reservation->status === 'confirmed' ? 'confirmed' : $reservation->status,
                'contract_status' => $contract?->status,
                'payment_status' => $solicitud->payment_status ?? $latestPayment?->status,
                'stripe_checkout_session_id' => $solicitud->stripe_checkout_session_id ?? $latestPayment?->stripe_checkout_session_id,
                'stripe_payment_intent_id' => $solicitud->stripe_payment_intent_id ?? $latestPayment?->stripe_payment_intent_id,
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

    private function resolveVisibleQuoteTotal(mixed ...$candidates): ?float
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            return round((float) $candidate, 2);
        }

        return null;
    }

    private function warnWhenQuoteTotalsDiverge(SolicitudVuelo $solicitud, array $candidates): void
    {
        $numericCandidates = collect($candidates)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $key) => ['source' => $key, 'value' => round((float) $value, 2)])
            ->values();

        if ($numericCandidates->count() < 2) {
            return;
        }

        $max = (float) $numericCandidates->max('value');
        $min = (float) $numericCandidates->min('value');

        if (abs($max - $min) <= 0.01) {
            return;
        }

        Log::warning('Client visible quote totals diverged', [
            'flight_request_id' => $solicitud->id,
            'candidates' => $numericCandidates->all(),
        ]);
    }

    public function solicitudParaOperador(SolicitudVuelo $solicitud): array
    {
        $match = $this->matchPreferidoParaOperador($solicitud);
        $visibilityPayload = $solicitud->visibility_payload ?? [];
        $assignedAircraft = $this->resolveAssignedAircraft($solicitud);
        $reservation = $this->resolveReservation($solicitud);
        $operation = $this->resolveLatestOperation($solicitud);
        $latestPayment = $this->resolveLatestPayment($reservation);
        $operatorStatus = $match?->status === 'rejected'
            ? 'rejected'
            : ($match?->status === 'accepted'
                ? 'accepted'
                : ($solicitud->workflow_status ?? $solicitud->status));
        $pricingPayload = $this->officialPricingPayload(
            is_array($solicitud->pricing_context) ? $solicitud->pricing_context : [],
            $solicitud->currency,
        );

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
            ...$pricingPayload,
            'currency' => $solicitud->currency,
            'pricing_context' => $solicitud->pricing_context,
            'visibility_payload' => $visibilityPayload,
            'provider_operational_release' => $visibilityPayload['provider_operational_release'] ?? null,
            'response_deadline' => $match?->response_deadline,
            'requirements' => $solicitud->requirements,
            'legs' => $this->visibleLegs($solicitud),
            'status' => $operatorStatus,
            'workflow_status' => $solicitud->workflow_status ?? $solicitud->status,
            'booking_status' => $solicitud->payment_status === 'paid' ? 'confirmed' : $reservation?->status,
            'contract_status' => $reservation?->contract?->status,
            'payment_status' => $solicitud->payment_status
                ?? $latestPayment?->status
                ?? $reservation?->status,
            'stripe_checkout_session_id' => $solicitud->stripe_checkout_session_id ?? $latestPayment?->stripe_checkout_session_id,
            'stripe_payment_intent_id' => $solicitud->stripe_payment_intent_id ?? $latestPayment?->stripe_payment_intent_id,
            'reservation_id' => $reservation?->id,
            'reservation' => $reservation ? [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'booking_status' => $reservation->status === 'confirmed' ? 'confirmed' : $reservation->status,
                'contract_status' => $reservation->contract?->status,
                'payment_status' => $solicitud->payment_status ?? $latestPayment?->status,
                'stripe_checkout_session_id' => $solicitud->stripe_checkout_session_id ?? $latestPayment?->stripe_checkout_session_id,
                'stripe_payment_intent_id' => $solicitud->stripe_payment_intent_id ?? $latestPayment?->stripe_payment_intent_id,
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
        $briefingPayload = is_array($visibilityPayload['briefing'] ?? null) ? $visibilityPayload['briefing'] : [];
        $assignedAircraft = $this->resolveAssignedAircraft($solicitud);
        $reservation = $this->resolveReservation($solicitud);
        $operation = $this->resolveLatestOperation($solicitud, preferCollection: false);
        $timeline = $operation
            ? ($operation->relationLoaded('timeline')
                ? $operation->timeline->sortByDesc('id')->values()
                : $operation->timeline()->latest('id')->get())
            : collect();
        $latestPayment = $this->resolveLatestPayment($reservation);
        $client = $this->resolveClient($solicitud);
        $pricingPayload = $this->officialPricingPayload(
            is_array($solicitud->pricing_context) ? $solicitud->pricing_context : [],
            $solicitud->currency,
        );

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
            ...$pricingPayload,
            'currency' => $solicitud->currency,
            'pricing_context' => $solicitud->pricing_context,
            'visibility_payload' => $visibilityPayload,
            'provider_operational_release' => $visibilityPayload['provider_operational_release'] ?? null,
            'operational_status' => $visibilityPayload['operational_status'] ?? null,
            'presentation_time' => $visibilityPayload['presentation_time'] ?? $briefingPayload['hora_presentacion'] ?? null,
            'presentation_place' => $visibilityPayload['presentation_place'] ?? $visibilityPayload['presentation_location'] ?? $briefingPayload['lugar_presentacion'] ?? null,
            'presentation_location' => $visibilityPayload['presentation_location'] ?? $visibilityPayload['presentation_place'] ?? $briefingPayload['lugar_presentacion'] ?? null,
            'briefing' => [
                'origen' => $briefingPayload['origen'] ?? $solicitud->origin,
                'destino' => $briefingPayload['destino'] ?? $solicitud->destination,
                'salida' => $briefingPayload['salida'] ?? $solicitud->departure_datetime,
                'pasajeros_autorizados' => $briefingPayload['pasajeros_autorizados'] ?? $solicitud->passengers,
                'hora_presentacion' => $briefingPayload['hora_presentacion'] ?? $visibilityPayload['presentation_time'] ?? null,
                'lugar_presentacion' => $briefingPayload['lugar_presentacion'] ?? $visibilityPayload['presentation_place'] ?? $visibilityPayload['presentation_location'] ?? null,
            ],
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
                'crew_notes' => $operation->crew_notes,
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

    private function officialPricingPayload(array $pricingContext, ?string $currency = null): array
    {
        if ($pricingContext === []) {
            return [];
        }

        return OfficialQuotePricingResource::build($pricingContext, array_filter([
            'currency' => $currency,
        ], fn ($value) => $value !== null && $value !== ''));
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

    private function resolveAssignedAircraft(SolicitudVuelo $solicitud, bool $withImages = false): ?Aeronave
    {
        if ($solicitud->relationLoaded('assignedAircraft')) {
            return $solicitud->assignedAircraft;
        }

        if (! $solicitud->assigned_aircraft_id) {
            return null;
        }

        if (! $withImages && filled($solicitud->assigned_aircraft_model)) {
            return null;
        }

        $relation = $solicitud->assignedAircraft();

        if ($withImages) {
            $relation->with('images');
        }

        return $relation->first();
    }

    private function resolveReservation(SolicitudVuelo $solicitud)
    {
        if ($solicitud->relationLoaded('reservation')) {
            return $solicitud->reservation;
        }

        return $solicitud->reservation()->with(['contract', 'latestPayment'])->first();
    }

    private function resolveLatestOperation(SolicitudVuelo $solicitud, bool $preferCollection = true): ?Operacion
    {
        if ($solicitud->relationLoaded('latestOperation')) {
            return $solicitud->latestOperation;
        }

        if ($preferCollection && $solicitud->relationLoaded('operaciones')) {
            return $solicitud->operaciones->sortByDesc('id')->first();
        }

        return $preferCollection
            ? $solicitud->operaciones()->latest('id')->first()
            : $solicitud->latestOperation()->first();
    }

    private function resolveLatestPayment($reservation)
    {
        if (! $reservation) {
            return null;
        }

        if ($reservation->relationLoaded('latestPayment')) {
            return $reservation->latestPayment;
        }

        if ($reservation->relationLoaded('payments')) {
            return $reservation->payments->sortByDesc('id')->first();
        }

        return $reservation->latestPayment()->first();
    }

    private function resolveClient(SolicitudVuelo $solicitud): ?Usuario
    {
        if ($solicitud->relationLoaded('client')) {
            return $solicitud->client;
        }

        if (! $solicitud->client_id) {
            return null;
        }

        return $solicitud->client()->first();
    }
}
