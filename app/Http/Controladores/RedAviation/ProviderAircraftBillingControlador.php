<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Aeronave;
use App\Modelos\AircraftBillingPayment;
use App\Modelos\SuscripcionAeronave;
use App\Servicios\Billing\BillingPlanServicio;
use Carbon\Carbon;
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
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/renta/operador/aeronaves?billing=success&aircraft_id='.$aircraft->id.'&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $data['cancel_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/renta/operador/aeronaves?billing=cancelled&aircraft_id='.$aircraft->id.'&session_id={CHECKOUT_SESSION_ID}';

        if (! str_contains($successUrl, 'session_id=')) {
            $successUrl .= (str_contains($successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
        }

        if (! str_contains($cancelUrl, 'session_id=')) {
            $cancelUrl .= (str_contains($cancelUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
        }

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

        $data = $request->validate([
            'session_id' => ['nullable', 'string'],
        ]);

        $sessionId = trim((string) ($data['session_id'] ?? ''));
        if ($sessionId !== '' && (str_contains($sessionId, 'CHECKOUT_SESSION_ID') || str_contains($sessionId, '{') || str_contains($sessionId, '}'))) {
            $sessionId = '';
        }

        $sessionId = $this->resolveCheckoutSessionIdForStatus($sessionId, (int) $providerId, (int) $aircraft->id);

        if ($sessionId !== '') {
            try {
                $this->reconcileStripeSession($sessionId, $aircraft, (int) $request->user()->id);
                $aircraft->refresh();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

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
                'payment_reference' => $subscription?->payment_reference ?: $latestPayment?->provider_subscription_id ?: $latestPayment?->provider_payment_id,
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

    private function resolveCheckoutSessionIdForStatus(string $sessionId, int $providerId, int $aircraftId): string
    {
        if ($sessionId !== '') {
            return $sessionId;
        }

        return (string) (AircraftBillingPayment::query()
            ->where('provider_id', $providerId)
            ->where('aircraft_id', $aircraftId)
            ->where('provider', 'stripe')
            ->whereNotNull('provider_checkout_id')
            ->orderByDesc('id')
            ->value('provider_checkout_id') ?? '');
    }

    private function reconcileStripeSession(string $sessionId, Aeronave $aircraft, int $userId): void
    {
        if ($sessionId === '' || ! config('services.stripe.secret')) {
            return;
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $session = Session::retrieve($sessionId, [
            'expand' => [
                'subscription',
                'subscription.latest_invoice.payment_intent',
                'payment_intent',
            ],
        ]);

        $metadata = (array) ($session->metadata?->toArray() ?? []);
        $context = (string) ($metadata['billing_context'] ?? '');
        if ($context !== 'provider_aircraft_subscription') {
            return;
        }

        $sessionAircraftId = (int) ($metadata['aircraft_id'] ?? 0);
        if ($sessionAircraftId > 0 && $sessionAircraftId !== (int) $aircraft->id) {
            abort(422, 'El checkout de Stripe no corresponde a esta aeronave.');
        }

        $payment = AircraftBillingPayment::query()
            ->where('provider_checkout_id', $sessionId)
            ->orWhere('id', (int) ($metadata['aircraft_billing_payment_id'] ?? 0))
            ->latest('id')
            ->first();

        if (! $payment) {
            return;
        }

        $subscriptionId = (string) ($session->subscription->id ?? $session->subscription ?? '');
        $paymentStatus = strtolower((string) ($session->payment_status ?? ''));
        $sessionStatus = strtolower((string) ($session->status ?? ''));
        $subscriptionStatus = strtolower((string) ($session->subscription->status ?? ''));

        if (! in_array($paymentStatus, ['paid', 'no_payment_required'], true)
            && $sessionStatus !== 'complete'
            && ! in_array($subscriptionStatus, ['active', 'trialing'], true)) {
            return;
        }

        $periodStart = null;
        $periodEnd = null;
        if ($subscriptionId !== '') {
            $periodStart = ! empty($session->subscription->current_period_start)
                ? Carbon::createFromTimestamp((int) $session->subscription->current_period_start)->toDateString()
                : null;
            $periodEnd = ! empty($session->subscription->current_period_end)
                ? Carbon::createFromTimestamp((int) $session->subscription->current_period_end)->toDateString()
                : null;
        }

        $providerPaymentId = (string) (
            $session->payment_intent->id
            ?? $session->subscription->latest_invoice->payment_intent->id
            ?? $session->payment_intent
            ?? ''
        );

        DB::transaction(function () use ($payment, $subscriptionId, $providerPaymentId, $session, $periodStart, $periodEnd, $aircraft, $userId) {
            $amount = ((int) ($session->amount_total ?? 0)) / 100;
            $resolvedUserId = $userId > 0 ? $userId : (int) DB::table('providers')->where('id', $payment->provider_id)->value('user_id');

            $payment->update([
                'status' => 'paid',
                'provider_payment_id' => $providerPaymentId !== '' ? $providerPaymentId : $payment->provider_payment_id,
                'provider_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $payment->provider_subscription_id,
                'amount' => $amount > 0 ? $amount : $payment->amount,
                'currency' => strtoupper((string) ($session->currency ?? $payment->currency ?? 'USD')),
                'billing_period_start' => $periodStart ?: $payment->billing_period_start,
                'billing_period_end' => $periodEnd ?: $payment->billing_period_end,
                'paid_at' => now(),
                'gateway_response' => json_decode(json_encode($session), true),
            ]);

            DB::table('aircraft')->where('id', $aircraft->id)->update([
                'billing_status' => 'active',
                'billing_plan_id' => $payment->billing_plan_id,
                'subscription_status' => 'active',
                'subscription_started_at' => $periodStart ? now()->createFromFormat('Y-m-d', $periodStart)->startOfDay() : now(),
                'subscription_ends_at' => $periodEnd ? now()->createFromFormat('Y-m-d', $periodEnd)->endOfDay() : now()->addMonth()->endOfDay(),
                'last_payment_at' => now(),
                'status' => 'active',
                'updated_at' => now(),
            ]);

            SuscripcionAeronave::updateOrCreate(
                ['aircraft_id' => $payment->aircraft_id, 'plan_id' => $payment->billing_plan_id],
                [
                    'user_id' => $resolvedUserId ?: null,
                    'status' => 'active',
                    'payment_provider' => 'stripe',
                    'payment_reference' => $subscriptionId !== '' ? $subscriptionId : ($providerPaymentId !== '' ? $providerPaymentId : $payment->provider_checkout_id),
                    'starts_at' => $periodStart ? Carbon::createFromFormat('Y-m-d', $periodStart)->startOfDay() : now(),
                    'ends_at' => $periodEnd ? Carbon::createFromFormat('Y-m-d', $periodEnd)->endOfDay() : now()->addMonth()->endOfDay(),
                    'updated_at' => now(),
                ]
            );
        });
    }
}
