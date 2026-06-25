<?php

namespace App\Servicios\Pagos;

class PaymentFeeCalculationServicio
{
    private const STRIPE_FEE_RATE = 0.036;
    private const MEMBERSHIP_ADMINISTRATIVE_FEE_RATE = 0.03;
    private const FLIGHT_ADMINISTRATIVE_FEE_RATE = 0.03;

    public function membershipBreakdown(float $baseAmount): array
    {
        $baseAmount = round(max($baseAmount, 0), 2);
        $stripeFee = round($baseAmount * self::STRIPE_FEE_RATE, 2);
        $administrativeFee = round($baseAmount * self::MEMBERSHIP_ADMINISTRATIVE_FEE_RATE, 2);
        $totalAmount = round($baseAmount + $stripeFee + $administrativeFee, 2);

        return [
            'base_amount' => $baseAmount,
            'stripe_fee' => $stripeFee,
            'administrative_fee' => $administrativeFee,
            'total_amount' => $totalAmount,
        ];
    }

    public function flightBreakdown(float $flightCost): array
    {
        $flightCost = round(max($flightCost, 0), 2);
        $stripeFee = round($flightCost * self::STRIPE_FEE_RATE, 2);
        $administrativeFee = round($flightCost * self::FLIGHT_ADMINISTRATIVE_FEE_RATE, 2);
        $totalAmount = round($flightCost + $stripeFee + $administrativeFee, 2);

        return [
            'flight_cost' => $flightCost,
            'base_amount' => $flightCost,
            'stripe_fee' => $stripeFee,
            'administrative_fee' => $administrativeFee,
            'total_amount' => $totalAmount,
        ];
    }
}
