<?php

namespace App\Servicios\Administracion;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\Cotizacion;
use App\Modelos\DocumentoAeronave;
use App\Modelos\DocumentoEmpresa;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\RegistroAuditoria;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Suscripcion;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AdminDashboardServicio
{
    public function build(array $filters = []): array
    {
        $dateFrom = $this->resolveDate($filters['date_from'] ?? null, now()->startOfMonth());
        $dateTo = $this->resolveDate($filters['date_to'] ?? null, now()->endOfDay());
        $currency = $this->normalizeCurrency($filters['currency'] ?? null);
        $providerId = isset($filters['provider_id']) ? (int) $filters['provider_id'] : null;
        $aircraftId = isset($filters['aircraft_id']) ? (int) $filters['aircraft_id'] : null;

        $paidPaymentsQuery = Pago::query()
            ->where('status', 'paid')
            ->whereBetween('created_at', [$dateFrom, $dateTo]);
        $this->applyPaymentFilters($paidPaymentsQuery, $currency, $providerId, $aircraftId);

        $refundedPaymentsQuery = Pago::query()
            ->where('status', 'refunded')
            ->whereBetween('updated_at', [$dateFrom, $dateTo]);
        $this->applyPaymentFilters($refundedPaymentsQuery, $currency, $providerId, $aircraftId);

        $paidPayments = $paidPaymentsQuery->get(['id', 'amount', 'currency', 'created_at']);
        $refundedPayments = $refundedPaymentsQuery->get(['id', 'amount', 'currency', 'gateway_response']);

        $paidRevenue = round((float) $paidPayments->sum(fn (Pago $payment) => (float) $payment->amount), 2);
        $refundedAmount = round((float) $refundedPayments->sum(fn (Pago $payment) => $this->resolveRefundAmount($payment)), 2);

        $quotesQuery = Cotizacion::query()->whereBetween('created_at', [$dateFrom, $dateTo]);
        $this->applyProviderAircraftFilters($quotesQuery, $providerId, $aircraftId);

        $reservationsQuery = Reserva::query()->whereBetween('created_at', [$dateFrom, $dateTo]);
        $this->applyProviderAircraftFilters($reservationsQuery, $providerId, $aircraftId);

        $subscriptionBaseQuery = Suscripcion::query();
        if ($providerId) {
            $subscriptionBaseQuery->whereHas('user.ownedProvider', fn ($query) => $query->whereKey($providerId));
        }

        $activeAircraftQuery = Aeronave::query()->whereIn('status', ['active', 'approved', 'aprobado', 'aprobada', 'trial_active']);
        $activeProvidersQuery = Proveedor::query()->whereIn('approval_status', ['approved', 'aprobado', 'aprobada', 'active']);
        $activeBlocksQuery = AircraftAvailabilityBlock::query()
            ->where('status', 'active')
            ->whereIn('block_type', ['manual_block', 'maintenance', 'inspection', 'repositioning', 'hold', 'booked'])
            ->where('end_datetime', '>=', $dateFrom)
            ->where('start_datetime', '<=', $dateTo);

        if ($providerId) {
            $activeAircraftQuery->where('provider_id', $providerId);
            $activeProvidersQuery->whereKey($providerId);
            $activeBlocksQuery->whereHas('aircraft', fn ($query) => $query->where('provider_id', $providerId));
        }

        if ($aircraftId) {
            $activeAircraftQuery->whereKey($aircraftId);
            $activeBlocksQuery->where('aircraft_id', $aircraftId);
        }

        $expiringCompanyDocsQuery = DocumentoEmpresa::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->copy()->addDays(30)]);
        if ($providerId) {
            $expiringCompanyDocsQuery->where('provider_id', $providerId);
        }

        $expiringAircraftDocsQuery = DocumentoAeronave::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->copy()->addDays(30)]);
        if ($providerId) {
            $expiringAircraftDocsQuery->where('provider_id', $providerId);
        }
        if ($aircraftId) {
            $expiringAircraftDocsQuery->where('aircraft_id', $aircraftId);
        }

        $upcomingFlightsQuery = SolicitudVuelo::query()
            ->whereNotNull('departure_datetime')
            ->whereBetween('departure_datetime', [now(), $dateTo])
            ->whereNotIn('status', ['cancelled', 'cancelada', 'completed', 'finalizada', 'expired']);
        if ($providerId) {
            $upcomingFlightsQuery->where('assigned_provider_id', $providerId);
        }
        if ($aircraftId) {
            $upcomingFlightsQuery->where('assigned_aircraft_id', $aircraftId);
        }

        $charts = [
            'revenue_by_period' => $paidPayments
                ->groupBy(fn (Pago $payment) => optional($payment->created_at)->format('Y-m-d') ?: now()->toDateString())
                ->map(fn (Collection $group, string $period) => ['period' => $period, 'value' => round((float) $group->sum('amount'), 2)])
                ->values()
                ->all(),
            'reservations_by_status' => (clone $reservationsQuery)
                ->selectRaw('status, count(*) as value')
                ->groupBy('status')
                ->orderBy('status')
                ->get()
                ->toArray(),
            'flights_by_status' => $this->flightStatuses($dateFrom, $dateTo, $providerId, $aircraftId)->values()->all(),
            'payments_by_status' => Pago::query()
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->when($currency, fn ($query) => $query->where('currency', $currency))
                ->selectRaw('status, count(*) as value')
                ->groupBy('status')
                ->orderBy('status')
                ->get()
                ->toArray(),
            'subscriptions_by_status' => (clone $subscriptionBaseQuery)
                ->selectRaw('status, count(*) as value')
                ->groupBy('status')
                ->orderBy('status')
                ->get()
                ->toArray(),
        ];

        $recentActivity = RegistroAuditoria::query()
            ->with('user:id,name')
            ->when($dateFrom, fn ($query) => $query->whereBetween('created_at', [$dateFrom, $dateTo]))
            ->when($providerId, fn ($query) => $query->where(function ($scope) use ($providerId) {
                $scope->where('entity', 'provider')
                    ->where('entity_id', (string) $providerId)
                    ->orWhereJsonContains('metadata->provider_id', $providerId)
                    ->orWhereJsonContains('after->provider_id', $providerId);
            }))
            ->when($aircraftId, fn ($query) => $query->where(function ($scope) use ($aircraftId) {
                $scope->where('entity', 'aircraft')
                    ->where('entity_id', (string) $aircraftId)
                    ->orWhereJsonContains('metadata->aircraft_id', $aircraftId)
                    ->orWhereJsonContains('after->aircraft_id', $aircraftId);
            }))
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (RegistroAuditoria $entry) => [
                'id' => $entry->id,
                'created_at' => optional($entry->created_at)->toIso8601String(),
                'action' => $entry->action,
                'title' => $entry->description ?: Str::of($entry->action)->replace('_', ' ')->title()->value(),
                'detail' => $entry->reason ?: data_get($entry->metadata, 'detail') ?: 'Actividad administrativa registrada.',
                'module' => $entry->module,
                'entity' => $entry->entity,
                'entity_id' => $entry->entity_id,
                'actor' => $entry->user?->name,
                'result' => $entry->result,
            ])
            ->values()
            ->all();

        return [
            'summary' => [
                'paid_revenue' => $paidRevenue,
                'refunded_amount' => $refundedAmount,
                'net_revenue' => round($paidRevenue - $refundedAmount, 2),
                'quotes_count' => (clone $quotesQuery)->count(),
                'confirmed_reservations' => (clone $reservationsQuery)->where('status', 'confirmed')->count(),
                'pending_payments' => Pago::query()->whereBetween('created_at', [$dateFrom, $dateTo])->when($currency, fn ($query) => $query->where('currency', $currency))->where('status', 'pending')->count(),
                'failed_payments' => Pago::query()->whereBetween('created_at', [$dateFrom, $dateTo])->when($currency, fn ($query) => $query->where('currency', $currency))->where('status', 'failed')->count(),
                'active_aircraft' => $activeAircraftQuery->count(),
                'active_providers' => $activeProvidersQuery->count(),
                'active_subscriptions' => (clone $subscriptionBaseQuery)->where('status', 'active')->count(),
                'expired_subscriptions' => (clone $subscriptionBaseQuery)->whereIn('status', ['expired', 'cancelled', 'canceled', 'past_due'])->count(),
                'upcoming_flights' => $upcomingFlightsQuery->count(),
                'documents_expiring_soon' => $expiringCompanyDocsQuery->count() + $expiringAircraftDocsQuery->count(),
                'active_operational_blocks' => $activeBlocksQuery->count(),
            ],
            'charts' => $charts,
            'recent_activity' => $recentActivity,
            'filters' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'currency' => $currency,
                'provider_id' => $providerId,
                'aircraft_id' => $aircraftId,
            ],
        ];
    }

    private function applyPaymentFilters($query, ?string $currency, ?int $providerId, ?int $aircraftId): void
    {
        $query
            ->when($currency, fn ($scope) => $scope->where('currency', $currency))
            ->when($providerId, fn ($scope) => $scope->whereHas('reservation', fn ($reservation) => $reservation->where('provider_id', $providerId)))
            ->when($aircraftId, fn ($scope) => $scope->whereHas('reservation', fn ($reservation) => $reservation->where('aircraft_id', $aircraftId)));
    }

    private function applyProviderAircraftFilters($query, ?int $providerId, ?int $aircraftId): void
    {
        $query
            ->when($providerId, fn ($scope) => $scope->where('provider_id', $providerId))
            ->when($aircraftId, fn ($scope) => $scope->where('aircraft_id', $aircraftId));
    }

    private function flightStatuses(Carbon $dateFrom, Carbon $dateTo, ?int $providerId, ?int $aircraftId): Collection
    {
        $query = SolicitudVuelo::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->select(['id', 'status', 'workflow_status', 'payment_status', 'assigned_provider_id', 'assigned_aircraft_id']);

        if ($providerId) {
            $query->where('assigned_provider_id', $providerId);
        }
        if ($aircraftId) {
            $query->where('assigned_aircraft_id', $aircraftId);
        }

        return $query->get()
            ->groupBy(fn (SolicitudVuelo $flight) => $this->normalizeFlightStatus($flight))
            ->map(fn (Collection $group, string $status) => ['status' => $status, 'value' => $group->count()])
            ->values();
    }

    private function normalizeFlightStatus(SolicitudVuelo $flight): string
    {
        $workflow = Str::of((string) ($flight->workflow_status ?? ''))->trim()->lower()->value();
        $status = Str::of((string) ($flight->status ?? ''))->trim()->lower()->value();

        return match (true) {
            in_array($workflow, ['cancelada', 'cancelled'], true) || in_array($status, ['cancelled', 'cancelada'], true) => 'cancelled',
            in_array($workflow, ['en curso', 'tracking_live', 'flight_live'], true) || $status === 'in_progress' => 'in_progress',
            in_array($workflow, ['servicio completado', 'finalizada', 'completed'], true) || $status === 'completed' => 'completed',
            in_array($workflow, ['asignada', 'flight_confirmed', 'operational_ready'], true) || in_array($status, ['reserved', 'confirmed'], true) => 'confirmed',
            in_array($workflow, ['preparing', 'preparacion', 'tracking_ready'], true) => 'preparing',
            default => 'scheduled',
        };
    }

    private function normalizeCurrency(?string $currency): ?string
    {
        $normalized = strtoupper(trim((string) $currency));

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveDate(mixed $value, Carbon $fallback): Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value) : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function resolveRefundAmount(Pago $payment): float
    {
        $gatewayRefund = data_get($payment->gateway_response, 'amount_refunded');
        if (is_numeric($gatewayRefund)) {
            return min((float) $payment->amount, (float) $gatewayRefund);
        }

        return (float) $payment->amount;
    }
}
