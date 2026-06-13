<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Aeronave;
use App\Modelos\AircraftBillingPayment;
use App\Modelos\SuscripcionAeronave;
use App\Servicios\Billing\BillingPlanServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class ProviderAircraftBillingControlador extends ControladorBase
{
    public function __construct(private readonly BillingPlanServicio $billingPlanServicio)
    {
    }

    public function create(Request $request, Aeronave $aircraft)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $providerId = $request->user()->resolvedProviderId();
        abort_if(! $providerId || (int) $aircraft->provider_id !== (int) $providerId, 403, 'No puedes cobrar esta aeronave.');

        $data = $request->validate([
            'success_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
        ]);

        $plan = $this->billingPlanServicio->findActiveByCode(BillingPlanServicio::PROVIDER_AIRCRAFT_MONTHLY_CODE);
        abort_if(! $plan, 404, 'No encontramos el plan mensual por aeronave.');

        $amount = (float) ($plan->amount ?: $plan->price_monthly ?: $plan->price ?: 0);
        abort_if($amount <= 0, 422, 'El plan mensual por aeronave no tiene un monto valido.');

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();

        $payment = AircraftBillingPayment::create([
            'provider_id' => $providerId,
            'aircraft_id' => $aircraft->id,
            'billing_plan_id' => $plan->id,
            'amount' => $amount,
            'currency' => strtoupper((string) ($plan->currency ?: 'USD')),
            'billing_period_start' => $periodStart,
            'billing_period_end' => $periodEnd,
            'status' => 'pending',
            'provider' => 'stripe',
        ]);

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $successUrl = $data['success_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/renta/admin/aeronaves?billing=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $data['cancel_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/renta/admin/aeronaves?billing=cancelled&session_id={CHECKOUT_SESSION_ID}';

        $metadata = [
            'billing_context' => 'provider_aircraft_subscription',
            'user_id' => (string) $request->user()->id,
            'provider_id' => (string) $providerId,
            'aircraft_id' => (string) $aircraft->id,
            'aircraft_billing_payment_id' => (string) $payment->id,
            'billing_plan_id' => (string) $plan->id,
            'plan_code' => (string) $plan->code,
        ];

        $session = Session::create([
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $request->user()->email,
            'client_reference_id' => (string) $payment->id,
            'metadata' => $metadata,
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower((string) ($plan->currency ?: 'USD')),
                    'product_data' => [
                        'name' => 'Mensualidad por aeronave: '.($aircraft->registration ?: $aircraft->model ?: 'Aeronave'),
                        'description' => $plan->description,
                    ],
                    'unit_amount' => (int) round($amount * 100),
                    'recurring' => [
                        'interval' => 'month',
                    ],
                ],
                'quantity' => 1,
            ]],
            'subscription_data' => [
                'metadata' => $metadata,
            ],
        ]);

        $payment->update([
            'provider_checkout_id' => $session->id,
            'gateway_response' => [
                'checkout_url' => $session->url,
            ],
        ]);

        DB::table('aircraft')->where('id', $aircraft->id)->update([
            'billing_status' => 'pending_payment',
            'billing_plan_id' => $plan->id,
            'subscription_status' => 'pending',
            'updated_at' => now(),
        ]);

        return $this->ok([
            'payment' => $payment->fresh('billingPlan'),
            'checkout_url' => $session->url,
            'checkout_session_id' => $session->id,
        ], 201);
    }

    public function status(Request $request, Aeronave $aircraft)
    {
        $providerId = $request->user()->resolvedProviderId();
        abort_if(! $providerId || (int) $aircraft->provider_id !== (int) $providerId, 403, 'No puedes consultar esta aeronave.');

        $latestPayment = AircraftBillingPayment::query()
            ->with('billingPlan:id,code,name,amount,currency')
            ->where('provider_id', $providerId)
            ->where('aircraft_id', $aircraft->id)
            ->latest('id')
            ->first();

        $subscription = SuscripcionAeronave::query()
            ->with('plan:id,code,name,amount,currency')
            ->where('aircraft_id', $aircraft->id)
            ->latest('id')
            ->first();

        return $this->ok([
            'aircraft' => [
                'id' => $aircraft->id,
                'registration' => $aircraft->registration,
                'model' => $aircraft->model,
                'billing_status' => $aircraft->billing_status,
                'billing_plan_id' => $aircraft->billing_plan_id,
                'subscription_status' => $aircraft->subscription_status,
                'subscription_started_at' => $aircraft->subscription_started_at,
                'subscription_ends_at' => $aircraft->subscription_ends_at,
                'last_payment_at' => $aircraft->last_payment_at,
            ],
            'latest_payment' => $latestPayment,
            'subscription' => $subscription,
        ]);
    }

    public function payments(Request $request)
    {
        $providerId = $request->user()->resolvedProviderId();
        abort_if(! $providerId, 404, 'Proveedor no encontrado.');

        $payments = AircraftBillingPayment::query()
            ->with(['aircraft:id,registration,model', 'billingPlan:id,code,name,amount,currency'])
            ->where('provider_id', $providerId)
            ->latest('id')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 100));

        return $this->ok([
            'payments' => $payments->items(),
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    private function ensureStripeIsConfigured(): ?JsonResponse
    {
        if (! config('services.stripe.secret') || ! config('services.stripe.publishable')) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe no esta configurado en el servidor.',
            ], 503);
        }

        return null;
    }
}
