<?php

namespace App\Servicios\Administracion;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\Cotizacion;
use App\Modelos\DocumentoAeronave;
use App\Modelos\DocumentoEmpresa;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Suscripcion;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

class AdminReportesServicio
{
    public function build(array $filters = []): array
    {
        $type = trim((string) ($filters['report_type'] ?? ''));
        $types = $type !== '' ? [$type] : [
            'payments',
            'net_revenue',
            'reservations',
            'flights',
            'providers',
            'aircraft',
            'subscriptions',
            'refunds',
            'documents_expiring',
            'operational_blocks',
        ];

        $reports = [];
        foreach ($types as $reportType) {
            $reports[$reportType] = $this->report($reportType, $filters);
        }

        return [
            'reports' => $reports,
            'applied_filters' => $this->publicFilters($filters),
        ];
    }

    private function report(string $type, array $filters): array
    {
        return match ($type) {
            'payments' => $this->paymentsReport($filters),
            'net_revenue' => $this->netRevenueReport($filters),
            'reservations' => $this->reservationsReport($filters),
            'flights' => $this->flightsReport($filters),
            'providers' => $this->providersReport($filters),
            'aircraft' => $this->aircraftReport($filters),
            'subscriptions' => $this->subscriptionsReport($filters),
            'refunds' => $this->refundsReport($filters),
            'documents_expiring' => $this->documentsExpiringReport($filters),
            'operational_blocks' => $this->operationalBlocksReport($filters),
            default => [
                'summary' => ['type' => $type],
                'rows' => [],
                'totals' => [],
                'pagination' => ['current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1],
                'applied_filters' => $this->publicFilters($filters),
            ],
        };
    }

    private function paymentsReport(array $filters): array
    {
        $query = Pago::query()->latest('id');
        $this->applyCommonPaymentFilters($query, $filters);

        $page = $query->paginate($this->perPage($filters));

        return $this->wrapReport(
            'payments',
            $page,
            fn (Pago $payment) => [
                'id' => $payment->id,
                'status' => $payment->status,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'payment_type' => $payment->payment_type,
                'provider' => $payment->provider,
                'reservation_id' => $payment->reservation_id,
                'subscription_id' => $payment->subscription_id,
                'created_at' => optional($payment->created_at)->toIso8601String(),
            ],
            [
                'count' => $query->count(),
                'amount' => round((float) $query->sum('amount'), 2),
            ],
        );
    }

    private function netRevenueReport(array $filters): array
    {
        $paymentsQuery = Pago::query()->where('status', 'paid');
        $refundsQuery = Pago::query()->where('status', 'refunded');
        $this->applyCommonPaymentFilters($paymentsQuery, $filters);
        $this->applyCommonPaymentFilters($refundsQuery, $filters);

        $paid = round((float) $paymentsQuery->sum('amount'), 2);
        $refunded = round((float) $refundsQuery->sum('amount'), 2);

        return [
            'summary' => ['type' => 'net_revenue', 'paid_revenue' => $paid, 'refunded_amount' => $refunded, 'net_revenue' => round($paid - $refunded, 2)],
            'rows' => [['paid_revenue' => $paid, 'refunded_amount' => $refunded, 'net_revenue' => round($paid - $refunded, 2)]],
            'totals' => ['paid_revenue' => $paid, 'refunded_amount' => $refunded, 'net_revenue' => round($paid - $refunded, 2)],
            'pagination' => ['current_page' => 1, 'per_page' => 1, 'total' => 1, 'last_page' => 1],
            'applied_filters' => $this->publicFilters($filters),
        ];
    }

    private function reservationsReport(array $filters): array
    {
        $query = Reserva::query()->latest('id');
        $this->applyReservationFilters($query, $filters);
        $page = $query->paginate($this->perPage($filters));

        return $this->wrapReport(
            'reservations',
            $page,
            fn (Reserva $reservation) => [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'provider_id' => $reservation->provider_id,
                'aircraft_id' => $reservation->aircraft_id,
                'client_id' => $reservation->client_id,
                'total_amount' => (float) $reservation->total_amount,
                'currency' => $reservation->currency,
                'confirmed_at' => optional($reservation->confirmed_at)->toIso8601String(),
            ],
            [
                'count' => $query->count(),
                'confirmed' => (clone $query)->where('status', 'confirmed')->count(),
            ],
        );
    }

    private function flightsReport(array $filters): array
    {
        $query = SolicitudVuelo::query()->latest('id');
        $this->applyFlightFilters($query, $filters);
        $page = $query->paginate($this->perPage($filters));

        return $this->wrapReport(
            'flights',
            $page,
            fn (SolicitudVuelo $flight) => [
                'id' => $flight->id,
                'status' => $flight->status,
                'workflow_status' => $flight->workflow_status,
                'provider_id' => $flight->assigned_provider_id,
                'aircraft_id' => $flight->assigned_aircraft_id,
                'client_id' => $flight->client_id,
                'origin' => $flight->origin,
                'destination' => $flight->destination,
                'departure_at' => optional($flight->departure_datetime)->toIso8601String(),
            ],
            ['count' => $query->count()],
        );
    }

    private function providersReport(array $filters): array
    {
        $query = Proveedor::query()->latest('id');
        if (($filters['provider_id'] ?? null) !== null) {
            $query->whereKey((int) $filters['provider_id']);
        }
        $page = $query->paginate($this->perPage($filters));

        return $this->wrapReport(
            'providers',
            $page,
            fn (Proveedor $provider) => [
                'id' => $provider->id,
                'company_name' => $provider->company_name,
                'commercial_name' => $provider->commercial_name,
                'approval_status' => $provider->approval_status,
            ],
            ['count' => $query->count()],
        );
    }

    private function aircraftReport(array $filters): array
    {
        $query = Aeronave::query()->latest('id');
        if (($filters['provider_id'] ?? null) !== null) {
            $query->where('provider_id', (int) $filters['provider_id']);
        }
        if (($filters['aircraft_id'] ?? null) !== null) {
            $query->whereKey((int) $filters['aircraft_id']);
        }
        $page = $query->paginate($this->perPage($filters));

        return $this->wrapReport(
            'aircraft',
            $page,
            fn (Aeronave $aircraft) => [
                'id' => $aircraft->id,
                'provider_id' => $aircraft->provider_id,
                'registration' => $aircraft->registration,
                'model' => $aircraft->model,
                'status' => $aircraft->status,
                'currency' => $aircraft->currency,
            ],
            ['count' => $query->count()],
        );
    }

    private function subscriptionsReport(array $filters): array
    {
        $query = Suscripcion::query()->latest('id');
        if (($filters['client_id'] ?? null) !== null) {
            $query->where('user_id', (int) $filters['client_id']);
        }
        $page = $query->paginate($this->perPage($filters));

        return $this->wrapReport(
            'subscriptions',
            $page,
            fn (Suscripcion $subscription) => [
                'id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'plan_id' => $subscription->plan_id,
                'status' => $subscription->status,
                'payment_status' => $subscription->payment_status,
                'expires_at' => optional($subscription->expires_at)->toIso8601String(),
            ],
            ['count' => $query->count()],
        );
    }

    private function refundsReport(array $filters): array
    {
        $query = Pago::query()->where('status', 'refunded')->latest('id');
        $this->applyCommonPaymentFilters($query, $filters);
        $page = $query->paginate($this->perPage($filters));

        return $this->wrapReport(
            'refunds',
            $page,
            fn (Pago $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'reservation_id' => $payment->reservation_id,
                'created_at' => optional($payment->updated_at ?: $payment->created_at)->toIso8601String(),
            ],
            ['count' => $query->count(), 'amount' => round((float) $query->sum('amount'), 2)],
        );
    }

    private function documentsExpiringReport(array $filters): array
    {
        $expiresBefore = $filters['date_to'] ?? now()->addDays(30)->toDateString();

        $providerDocs = DocumentoEmpresa::query()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $expiresBefore)
            ->when(($filters['provider_id'] ?? null) !== null, fn ($query) => $query->where('provider_id', (int) $filters['provider_id']))
            ->get()
            ->map(fn (DocumentoEmpresa $document) => [
                'id' => 'provider:'.$document->id,
                'type' => 'provider',
                'provider_id' => $document->provider_id,
                'aircraft_id' => null,
                'title' => $document->document_name,
                'expires_at' => optional($document->expires_at)->toIso8601String(),
            ]);

        $aircraftDocs = DocumentoAeronave::query()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $expiresBefore)
            ->when(($filters['provider_id'] ?? null) !== null, fn ($query) => $query->where('provider_id', (int) $filters['provider_id']))
            ->when(($filters['aircraft_id'] ?? null) !== null, fn ($query) => $query->where('aircraft_id', (int) $filters['aircraft_id']))
            ->get()
            ->map(fn (DocumentoAeronave $document) => [
                'id' => 'aircraft:'.$document->id,
                'type' => 'aircraft',
                'provider_id' => $document->provider_id,
                'aircraft_id' => $document->aircraft_id,
                'title' => $document->document_name ?: $document->document_type,
                'expires_at' => optional($document->expires_at)->toIso8601String(),
            ]);

        $rows = $providerDocs->concat($aircraftDocs)->sortBy('expires_at')->values();

        return [
            'summary' => ['type' => 'documents_expiring', 'count' => $rows->count()],
            'rows' => $rows->all(),
            'totals' => ['count' => $rows->count()],
            'pagination' => ['current_page' => 1, 'per_page' => $rows->count(), 'total' => $rows->count(), 'last_page' => 1],
            'applied_filters' => $this->publicFilters($filters),
        ];
    }

    private function operationalBlocksReport(array $filters): array
    {
        $query = AircraftAvailabilityBlock::query()->latest('id');
        $query
            ->when(($filters['provider_id'] ?? null) !== null, fn ($scope) => $scope->whereHas('aircraft', fn ($aircraft) => $aircraft->where('provider_id', (int) $filters['provider_id'])))
            ->when(($filters['aircraft_id'] ?? null) !== null, fn ($scope) => $scope->where('aircraft_id', (int) $filters['aircraft_id']))
            ->when(filled($filters['status'] ?? null), fn ($scope) => $scope->where('status', $filters['status']))
            ->when(filled($filters['date_from'] ?? null), fn ($scope) => $scope->whereDate('start_datetime', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($scope) => $scope->whereDate('end_datetime', '<=', $filters['date_to']));
        $page = $query->paginate($this->perPage($filters));

        return $this->wrapReport(
            'operational_blocks',
            $page,
            fn (AircraftAvailabilityBlock $block) => [
                'id' => $block->id,
                'aircraft_id' => $block->aircraft_id,
                'reservation_id' => $block->reservation_id,
                'block_type' => $block->block_type,
                'status' => $block->status,
                'reason' => $block->reason,
                'start_datetime' => optional($block->start_datetime)->toIso8601String(),
                'end_datetime' => optional($block->end_datetime)->toIso8601String(),
            ],
            ['count' => $query->count()],
        );
    }

    private function wrapReport(string $type, LengthAwarePaginator $page, callable $transformer, array $totals): array
    {
        return [
            'summary' => ['type' => $type, ...$totals],
            'rows' => collect($page->items())->map($transformer)->values()->all(),
            'totals' => $totals,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'applied_filters' => $this->publicFilters(request()?->all() ?? []),
        ];
    }

    private function applyCommonPaymentFilters($query, array $filters): void
    {
        $query
            ->when(filled($filters['status'] ?? null), fn ($scope) => $scope->where('status', $filters['status']))
            ->when(filled($filters['currency'] ?? null), fn ($scope) => $scope->where('currency', strtoupper(trim((string) $filters['currency']))))
            ->when(filled($filters['date_from'] ?? null), fn ($scope) => $scope->whereDate('created_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($scope) => $scope->whereDate('created_at', '<=', $filters['date_to']))
            ->when(($filters['provider_id'] ?? null) !== null, fn ($scope) => $scope->whereHas('reservation', fn ($reservation) => $reservation->where('provider_id', (int) $filters['provider_id'])))
            ->when(($filters['aircraft_id'] ?? null) !== null, fn ($scope) => $scope->whereHas('reservation', fn ($reservation) => $reservation->where('aircraft_id', (int) $filters['aircraft_id'])))
            ->when(($filters['client_id'] ?? null) !== null, fn ($scope) => $scope->where('user_id', (int) $filters['client_id']));
    }

    private function applyReservationFilters($query, array $filters): void
    {
        $query
            ->when(filled($filters['status'] ?? null), fn ($scope) => $scope->where('status', $filters['status']))
            ->when(filled($filters['currency'] ?? null), fn ($scope) => $scope->where('currency', strtoupper(trim((string) $filters['currency']))))
            ->when(filled($filters['date_from'] ?? null), fn ($scope) => $scope->whereDate('created_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($scope) => $scope->whereDate('created_at', '<=', $filters['date_to']))
            ->when(($filters['provider_id'] ?? null) !== null, fn ($scope) => $scope->where('provider_id', (int) $filters['provider_id']))
            ->when(($filters['aircraft_id'] ?? null) !== null, fn ($scope) => $scope->where('aircraft_id', (int) $filters['aircraft_id']))
            ->when(($filters['client_id'] ?? null) !== null, fn ($scope) => $scope->where('client_id', (int) $filters['client_id']));
    }

    private function applyFlightFilters($query, array $filters): void
    {
        $query
            ->when(filled($filters['status'] ?? null), fn ($scope) => $scope->where('status', $filters['status']))
            ->when(filled($filters['date_from'] ?? null), fn ($scope) => $scope->whereDate('departure_datetime', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($scope) => $scope->whereDate('departure_datetime', '<=', $filters['date_to']))
            ->when(($filters['provider_id'] ?? null) !== null, fn ($scope) => $scope->where('assigned_provider_id', (int) $filters['provider_id']))
            ->when(($filters['aircraft_id'] ?? null) !== null, fn ($scope) => $scope->where('assigned_aircraft_id', (int) $filters['aircraft_id']))
            ->when(($filters['client_id'] ?? null) !== null, fn ($scope) => $scope->where('client_id', (int) $filters['client_id']));
    }

    private function publicFilters(array $filters): array
    {
        return [
            'report_type' => $filters['report_type'] ?? null,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'status' => $filters['status'] ?? null,
            'currency' => $filters['currency'] ?? null,
            'provider_id' => $filters['provider_id'] ?? null,
            'aircraft_id' => $filters['aircraft_id'] ?? null,
            'client_id' => $filters['client_id'] ?? null,
        ];
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 25)));
    }
}
