<?php

namespace App\Servicios\RedAviation;

use App\Modelos\Aeronave;
use App\Modelos\ImagenAeronave;
use App\Modelos\Operacion;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;

class VisibilidadServicio
{
    public function solicitudParaCliente(SolicitudVuelo $solicitud): array
    {
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
            'aircraft_type' => $solicitud->aircraft_type,
            'requirements' => $solicitud->requirements,
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
        return [
            'id' => $solicitud->id,
            'origin' => $solicitud->origin,
            'destination' => $solicitud->destination,
            'departure_datetime' => $solicitud->departure_datetime,
            'passengers' => $solicitud->passengers,
            'aircraft_type' => $solicitud->aircraft_type,
            'requirements' => $solicitud->requirements,
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
        $visibleImages = $aircraft->relationLoaded('images')
            ? $aircraft->images
                ->where('visible_to_client', true)
                ->sortBy([
                    ['is_main', 'desc'],
                    ['sort_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
            : collect();

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
}
