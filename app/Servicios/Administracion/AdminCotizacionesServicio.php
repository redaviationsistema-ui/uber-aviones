<?php

namespace App\Servicios\Administracion;

use App\Modelos\Cotizacion;
use App\Modelos\RegistroAuditoria;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminCotizacionesServicio
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Cotizacion::query()
            ->with([
                'flightRequest.client:id,name,email',
                'flightRequest.legs:id,flight_request_id,origin,destination,departure_datetime,arrival_datetime,passengers,status',
                'provider:id,company_name,commercial_name',
                'aircraft:id,provider_id,model,registration,status',
            ])
            ->latest('id');

        $query
            ->when(filled($filters['status'] ?? null), fn ($scope) => $scope->where('status', $filters['status']))
            ->when(($filters['client_id'] ?? null) !== null, fn ($scope) => $scope->whereHas('flightRequest', fn ($flightRequest) => $flightRequest->where('client_id', (int) $filters['client_id'])))
            ->when(($filters['provider_id'] ?? null) !== null, fn ($scope) => $scope->where('provider_id', (int) $filters['provider_id']))
            ->when(($filters['aircraft_id'] ?? null) !== null, fn ($scope) => $scope->where('aircraft_id', (int) $filters['aircraft_id']))
            ->when(filled($filters['origin'] ?? null), fn ($scope) => $scope->whereHas('flightRequest', fn ($flightRequest) => $flightRequest->where('origin', 'like', '%'.$filters['origin'].'%')))
            ->when(filled($filters['destination'] ?? null), fn ($scope) => $scope->whereHas('flightRequest', fn ($flightRequest) => $flightRequest->where('destination', 'like', '%'.$filters['destination'].'%')))
            ->when(filled($filters['date_from'] ?? null), fn ($scope) => $scope->whereDate('created_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($scope) => $scope->whereDate('created_at', '<=', $filters['date_to']));

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 25))));
    }

    public function detail(Cotizacion $quote): array
    {
        $quote->loadMissing([
            'flightRequest.client:id,name,email,phone',
            'flightRequest.legs:id,flight_request_id,origin,destination,departure_datetime,arrival_datetime,passengers,status',
            'flightRequest.reservation.latestPayment',
            'provider:id,company_name,commercial_name',
            'aircraft:id,provider_id,model,registration,status,currency,hourly_rate',
            'items',
        ]);

        $audit = RegistroAuditoria::query()
            ->where(function ($query) use ($quote) {
                $query->where('entity', 'quote')->where('entity_id', (string) $quote->id)
                    ->orWhereJsonContains('metadata->quote_id', $quote->id)
                    ->orWhereJsonContains('after->quote_id', $quote->id);
            })
            ->latest('id')
            ->limit(20)
            ->get(['id', 'action', 'module', 'result', 'reason', 'created_at']);

        return [
            'id' => $quote->id,
            'title' => $quote->quote_code ?? 'Cotizacion #'.$quote->id,
            'status' => $quote->status,
            'currency' => $quote->currency,
            'subtotal' => $quote->subtotal,
            'taxes' => $quote->taxes,
            'fees' => $quote->fees,
            'total' => $quote->total,
            'expires_at' => optional($quote->expires_at)->toIso8601String(),
            'created_at' => optional($quote->created_at)->toIso8601String(),
            'client' => [
                'id' => $quote->flightRequest?->client?->id,
                'name' => $quote->flightRequest?->client?->name,
                'email' => $quote->flightRequest?->client?->email,
                'phone' => $quote->flightRequest?->client?->phone,
            ],
            'provider' => [
                'id' => $quote->provider?->id,
                'name' => $quote->provider?->commercial_name ?: $quote->provider?->company_name,
            ],
            'aircraft' => [
                'id' => $quote->aircraft?->id,
                'name' => trim((string) (($quote->aircraft?->registration ?? '').' '.($quote->aircraft?->model ?? ''))),
                'status' => $quote->aircraft?->status,
            ],
            'route' => [
                'origin' => $quote->flightRequest?->origin,
                'destination' => $quote->flightRequest?->destination,
                'departure_at' => optional($quote->flightRequest?->departure_datetime)->toIso8601String(),
                'arrival_at' => optional($quote->flightRequest?->return_datetime)->toIso8601String(),
                'legs' => $quote->flightRequest?->legs?->map(fn ($leg) => [
                    'origin' => $leg->origin,
                    'destination' => $leg->destination,
                    'departure_at' => optional($leg->departure_datetime)->toIso8601String(),
                    'arrival_at' => optional($leg->arrival_datetime)->toIso8601String(),
                    'passengers' => $leg->passengers,
                    'status' => $leg->status,
                ])->values()->all() ?? [],
            ],
            'reservation' => $quote->flightRequest?->reservation ? [
                'id' => $quote->flightRequest->reservation->id,
                'status' => $quote->flightRequest->reservation->status,
                'reservation_code' => $quote->flightRequest->reservation->reservation_code,
            ] : null,
            'payment' => $quote->flightRequest?->reservation?->latestPayment ? [
                'id' => $quote->flightRequest->reservation->latestPayment->id,
                'status' => $quote->flightRequest->reservation->latestPayment->status,
                'paid_at' => optional($quote->flightRequest->reservation->latestPayment->paid_at)->toIso8601String(),
            ] : null,
            'price_breakdown' => [
                'subtotal' => $quote->subtotal,
                'taxes' => $quote->taxes,
                'fees' => $quote->fees,
                'total' => $quote->total,
            ],
            'audit' => $audit,
        ];
    }
}
