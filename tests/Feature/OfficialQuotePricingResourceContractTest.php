<?php

namespace Tests\Feature;

use App\Http\Resources\RedAviation\OfficialQuotePricingResource;
use App\Servicios\Vuelos\FlightPricingService;
use Tests\TestCase;

class OfficialQuotePricingResourceContractTest extends TestCase
{
    public function test_resource_exposes_single_official_source_of_truth_with_compatibility_aliases(): void
    {
        $payload = OfficialQuotePricingResource::build([
            'display_route_hours' => 4.5833,
            'operational_flight_hours' => 4.5833,
            'direct_flight_hours' => 4.2,
            'final_billable_hours' => 4.5851,
            'billable_hours' => 4.5851,
            'billable_minutes' => 275.106,
            'distance_nm' => 812.22,
            'distance_km' => 1504.23,
            'flight_cost' => 10545.69,
            'airport_expenses' => 900,
            'stripe_fee' => 120.5,
            'administrative_fee' => 80.25,
            'subtotal' => 11445.69,
            'taxes' => 1831.31,
            'total_amount' => 13277.00,
            'currency' => 'USD',
            'pricing_version' => FlightPricingService::FORMULA_VERSION,
        ]);

        $this->assertSame('pricing_breakdown', $payload['official_pricing_source']);
        $this->assertSame('pricing_breakdown.display_route_hours', $payload['official_display_time_field']);
        $this->assertSame('pricing_breakdown.final_billable_hours', $payload['official_billable_hours_field']);
        $this->assertSame('pricing_breakdown.total_amount', $payload['official_total_field']);
        $this->assertSame((float) $payload['pricing_breakdown']['display_route_hours'], (float) $payload['display_route_hours']);
        $this->assertSame((float) $payload['pricing_breakdown']['final_billable_hours'], (float) $payload['final_billable_hours']);
        $this->assertSame((float) $payload['pricing_breakdown']['billable_hours'], (float) $payload['billable_hours']);
        $this->assertSame((float) $payload['pricing_breakdown']['total_amount'], (float) $payload['total_amount']);
        $this->assertSame((float) $payload['pricing_breakdown']['total_amount'], (float) $payload['total']);
        $this->assertSame((string) $payload['time'], (string) $payload['card_time']);
        $this->assertSame((string) $payload['time'], (string) $payload['trip_time']);
        $this->assertSame((string) $payload['time'], (string) $payload['display_route_duration']);
        $this->assertSame('4 h 35 min', $payload['time']);
        $this->assertSame('4 h 35 min', $payload['billable_flight_time']);
    }

    public function test_resource_falls_back_to_legacy_fields_without_changing_contract_shape(): void
    {
        $payload = OfficialQuotePricingResource::build([
            'route_operational_display_hours' => '3.75',
            'route_operational_hours' => '3.75',
            'pricing_hours' => '3.8012',
            'total_billed_hours' => '3.8012',
            'total' => '9876.54',
            'tax' => '123.45',
            'expense_fee' => '456.78',
            'initial_repositioning_cost' => '99.10',
        ], [
            'currency' => 'MXN',
            'distance_nm' => 100,
            'distance_km' => 185.2,
        ]);

        $this->assertSame('pricing_breakdown', $payload['official_pricing_source']);
        $this->assertSame(3.75, (float) $payload['pricing_breakdown']['display_route_hours']);
        $this->assertSame(3.8012, (float) $payload['pricing_breakdown']['final_billable_hours']);
        $this->assertSame(3.8012, (float) $payload['pricing_breakdown']['billable_hours']);
        $this->assertSame(9876.54, (float) $payload['pricing_breakdown']['total_amount']);
        $this->assertSame(456.78, (float) $payload['pricing_breakdown']['airport_expenses']);
        $this->assertSame(99.10, (float) $payload['pricing_breakdown']['repositioning_cost']);
        $this->assertSame('MXN', $payload['currency']);
        $this->assertSame('3 h 45 min', $payload['time']);
    }
}
