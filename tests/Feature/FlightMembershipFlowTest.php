<?php

namespace Tests\Feature;

use App\Http\Controladores\StripeWebhookControlador;
use App\Modelos\Aeronave;
use App\Modelos\Cotizacion;
use App\Modelos\FlightMembership;
use App\Modelos\FlightMembershipBenefitLedger;
use App\Modelos\FlightMembershipPeriod;
use App\Modelos\FlightMembershipPlan;
use App\Modelos\Proveedor;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class FlightMembershipFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_invoice_paid_activates_flight_membership_and_is_idempotent(): void
    {
        $this->seed();

        $user = Usuario::query()->create([
            'name' => 'Cliente Membresia',
            'email' => 'cliente.membresia@test.dev',
            'password' => Hash::make('password123'),
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
        ]);

        $plan = FlightMembershipPlan::query()->create([
            'name' => 'Membresia Ejecutiva',
            'slug' => 'membresia-ejecutiva',
            'description' => 'Beneficios mensuales de vuelo.',
            'price' => 2500,
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'included_flights' => 1,
            'included_hours' => 5,
            'included_credit_amount' => 1000,
            'discount_percentage' => 10,
            'rollover_flights' => false,
            'rollover_hours' => true,
            'rollover_credits' => true,
            'is_active' => true,
        ]);

        $membership = FlightMembership::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'pending_payment',
            'stripe_subscription_id' => 'sub_test_membership_001',
            'stripe_checkout_session_id' => 'cs_test_membership_001',
        ]);

        $invoice = (object) [
            'id' => 'in_test_membership_001',
            'subscription' => 'sub_test_membership_001',
            'customer' => 'cus_test_membership_001',
            'currency' => 'usd',
            'amount_paid' => 250000,
            'lines' => (object) [
                'data' => [
                    [
                        'period' => [
                            'start' => now()->startOfMonth()->timestamp,
                            'end' => now()->addMonthNoOverflow()->startOfMonth()->timestamp,
                        ],
                    ],
                ],
            ],
            'metadata' => (object) [
                'context' => 'flight_membership_subscription',
                'billing_context' => 'flight_membership_subscription',
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'membership_id' => (string) $membership->id,
                'plan_name' => $plan->name,
            ],
        ];

        $method = new ReflectionMethod(StripeWebhookControlador::class, 'handleInvoicePaid');
        $method->setAccessible(true);
        $controller = app(StripeWebhookControlador::class);
        $method->invoke($controller, $invoice);
        $method->invoke($controller, $invoice);

        $this->assertDatabaseHas('flight_memberships', [
            'id' => $membership->id,
            'status' => 'active',
            'stripe_customer_id' => 'cus_test_membership_001',
            'stripe_subscription_id' => 'sub_test_membership_001',
            'last_invoice_id' => 'in_test_membership_001',
        ]);

        $this->assertSame(1, FlightMembershipPeriod::query()->count());
        $this->assertSame(3, FlightMembershipBenefitLedger::query()->where('entry_type', 'grant')->count());

        $period = FlightMembershipPeriod::query()->firstOrFail();
        $this->assertSame(1.0, (float) $period->granted_flights);
        $this->assertSame(5.0, (float) $period->granted_hours);
        $this->assertSame(1000.0, (float) $period->granted_credit);
    }

    public function test_quote_show_includes_flight_membership_preview_for_client(): void
    {
        $this->seed();

        $provider = Proveedor::factory()->create();
        $user = Usuario::query()->create([
            'name' => 'Cliente Preview',
            'email' => 'cliente.preview@test.dev',
            'password' => Hash::make('password123'),
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
        ]);
        $token = TokenApi::issue($user, 'flight-membership-test');

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $provider->id,
            'hourly_rate' => 5000,
            'currency' => 'USD',
        ]);

        $flightRequest = SolicitudVuelo::factory()->create([
            'client_id' => $user->id,
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'pricing_context' => [
                'flight_cost' => 15000,
                'billable_hours' => 2,
                'hourly_rate' => 5000,
            ],
            'currency' => 'USD',
            'status' => 'quoted',
            'workflow_status' => 'cotizada',
        ]);

        $quote = Cotizacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'aircraft_id' => $aircraft->id,
            'provider_id' => $provider->id,
            'subtotal' => 15000,
            'taxes' => 0,
            'fees' => 0,
            'total' => 15000,
            'currency' => 'USD',
            'status' => 'sent',
            'expires_at' => now()->addDays(2),
        ]);

        $plan = FlightMembershipPlan::query()->create([
            'name' => 'Membresia Horas',
            'slug' => 'membresia-horas',
            'description' => 'Horas y descuento.',
            'price' => 1500,
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'included_hours' => 5,
            'included_credit_amount' => 1000,
            'discount_percentage' => 10,
            'is_active' => true,
        ]);

        $membership = FlightMembership::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->addMonthNoOverflow()->endOfMonth(),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->addMonthNoOverflow()->endOfMonth(),
        ]);

        $period = FlightMembershipPeriod::query()->create([
            'flight_membership_id' => $membership->id,
            'membership_period_key' => 'test-period-preview',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->addMonthNoOverflow()->endOfMonth(),
            'status' => 'active',
        ]);

        FlightMembershipBenefitLedger::query()->create([
            'flight_membership_id' => $membership->id,
            'flight_membership_period_id' => $period->id,
            'membership_period_key' => $period->membership_period_key,
            'entry_type' => 'grant',
            'benefit_type' => 'hour',
            'quantity' => 5,
            'amount' => 0,
            'status' => 'posted',
            'reference' => 'grant:hour',
            'occurred_at' => now(),
        ]);

        FlightMembershipBenefitLedger::query()->create([
            'flight_membership_id' => $membership->id,
            'flight_membership_period_id' => $period->id,
            'membership_period_key' => $period->membership_period_key,
            'entry_type' => 'grant',
            'benefit_type' => 'credit',
            'quantity' => 0,
            'amount' => 1000,
            'status' => 'posted',
            'reference' => 'grant:credit',
            'occurred_at' => now(),
        ]);

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/cliente/cotizaciones/'.$quote->id);

        $response
            ->assertOk()
            ->assertJsonPath('flight_membership_preview.membership_available', true)
            ->assertJsonPath('flight_membership_preview.membership_id', $membership->id)
            ->assertJsonPath('flight_membership_preview.benefits.hours_available', 5)
            ->assertJsonPath('flight_membership_preview.application_preview.hours_to_use', 2)
            ->assertJsonPath('flight_membership_preview.application_preview.credit_to_use', 1000)
            ->assertJsonPath('flight_membership_preview.application_preview.discount_amount', 400)
            ->assertJsonPath('flight_membership_preview.application_preview.estimated_total', 3600);
    }
}
