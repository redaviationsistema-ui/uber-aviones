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
    public function solicitudParaCliente(SolicitudVuelo $solicitud): array
    {
        $preferredMatch = $this->matchPreferidoParaCliente($solicitud);
        $visibilityPayload = $solicitud->visibility_payload ?? [];
        $assignedAircraft = $solicitud->relationLoaded('assignedAircraft')
            ? $solicitud->assignedAircraft
            : $solicitud->assignedAircraft()->with('images')->first();

        $chat = $solicitud->relationLoaded('chatsProtegidos')
            ? $solicitud->chatsProtegidos->sortByDesc('id')->first()
            : $solicitud->chatsProtegidos()->latest('id')->first();

        $operacion = $solicitud->relationLoaded('operaciones')
            ? $solicitud->operaciones->sortByDesc('id')->first()
            : $solicitud->operaciones()->latest('id')->first();

        $timeline = $operacion
            ? ($operacion->relationLoaded('timeline')
                ? $operacion->timeline->sortByDesc('id')->take(4)->values()
                : $operacion->timeline()->latest('id')->limit(4)->get())
            : collect();

        return [
            'id' => $solicitud->id,
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
            'requirements' => $solicitud->requirements,
            'legs' => $this->visibleLegs($solicitud),
            'notes' => $solicitud->notes,
            'status' => $solicitud->workflow_status ?? $solicitud->status,
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
            'matched_options' => $solicitud->matches->map(fn ($match) => [
                'id' => $match->id,
                'aircraft_id' => $match->aircraft_id,
                'status' => $match->status,
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
            ])->values(),
        ];
    }

    public function solicitudParaOperador(SolicitudVuelo $solicitud): array
    {
        $match = $this->matchPreferidoParaOperador($solicitud);
        $visibilityPayload = $solicitud->visibility_payload ?? [];
        $assignedAircraft = $solicitud->relationLoaded('assignedAircraft')
            ? $solicitud->assignedAircraft
            : $solicitud->assignedAircraft()->first();

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
            'quote_total' => $match?->estimated_price,
            'response_deadline' => $match?->response_deadline,
            'requirements' => $solicitud->requirements,
            'legs' => $this->visibleLegs($solicitud),
            'status' => $solicitud->workflow_status ?? $solicitud->status,
            'client' => [
                'display_name' => 'Cliente Red Aviation #'.$solicitud->client_id,
            ],
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
