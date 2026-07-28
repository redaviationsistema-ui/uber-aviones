<?php

namespace App\Servicios\Pagos;

use App\Modelos\Reserva;
use App\Servicios\Aeronaves\AircraftAvailabilityService;

class ReservationPaymentAuthorizationService
{
    public function __construct(
        private readonly AircraftAvailabilityService $availabilityService,
    ) {}

    public function evaluate(Reserva $reservation, bool $recoverHold = false): array
    {
        $reservation->loadMissing(['flightRequest', 'aircraft', 'contract', 'latestPayment']);
        $blockingReasons = [];

        if (! in_array((string) $reservation->status, ['pending_payment', 'paid', 'confirmed'], true)) {
            $blockingReasons[] = 'RESERVATION_STATUS_NOT_PAYABLE';
        }

        if (! $reservation->flightRequest) {
            $blockingReasons[] = 'REQUEST_NOT_FOUND';
        } elseif (in_array((string) $reservation->flightRequest->status, ['cancelled', 'canceled', 'expired'], true)) {
            $blockingReasons[] = 'REQUEST_NOT_ACTIVE';
        }

        $contractCompleted = $reservation->contract
            && strtolower((string) $reservation->contract->docusign_status) === 'completed'
            && $reservation->contract->completed_at !== null;
        if (! $contractCompleted) {
            $blockingReasons[] = 'CONTRACT_NOT_COMPLETED';
        }

        if (! $reservation->aircraft || ! in_array(strtolower((string) $reservation->aircraft->status), ['active', 'available', 'approved'], true)) {
            $blockingReasons[] = 'AIRCRAFT_NOT_ACTIVE';
        }

        $availability = $this->availabilityService->evaluateReservationPaymentAvailability($reservation, $recoverHold);
        if (! ($availability['can_pay'] ?? false)) {
            $blockingReasons[] = ($availability['invalid_reason'] ?? '') === 'aircraft_booked_by_other_reservation'
                ? 'AIRCRAFT_NOT_AVAILABLE'
                : 'AIRCRAFT_HOLD_INVALID';
        }

        $paymentStatus = strtolower((string) ($reservation->latestPayment?->status ?? 'pending'));
        if ($paymentStatus === 'paid') {
            $blockingReasons[] = 'PAYMENT_ALREADY_CONFIRMED';
        }

        $blockingReasons = array_values(array_unique($blockingReasons));
        $authorized = $blockingReasons === [];
        $canPay = $authorized;
        $aircraftAvailable = (bool) data_get($availability, 'availability.available', false);
        $holdValid = (bool) ($availability['hold_valid'] ?? false);
        $reservationBooked = (bool) ($availability['reservation_booked'] ?? false);
        $invalidReason = $availability['invalid_reason'] ?? null;

        return [
            'authorized' => $authorized,
            'can_pay' => $canPay,
            'request_status' => $reservation->flightRequest?->status,
            'reservation_status' => $reservation->status,
            'contract_status' => $reservation->contract?->docusign_status ?? $reservation->contract?->status,
            'aircraft_available' => $aircraftAvailable,
            'hold_valid' => $holdValid,
            'reservation_booked' => $reservationBooked,
            'payment_status' => $paymentStatus,
            'blocking_reasons' => $blockingReasons,
            'invalid_reason' => $invalidReason,
            'availability' => [
                ...($availability['availability'] ?? []),
                'available' => $aircraftAvailable,
            ],
            'payment_availability' => $availability,
        ];
    }
}
