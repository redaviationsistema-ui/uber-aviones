<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Demo;
use App\Modelos\Pago;
use App\Modelos\Plan;
use App\Modelos\Suscripcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

class SuscripcionControlador extends ControladorBase
{
    public function startTrial(Request $request)
    {
        $demo = Demo::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'started_at' => now(),
                'expires_at' => now()->addDays(15),
                'status' => 'active',
            ]
        );

        return $this->ok(['demo' => $demo, 'subscription_status' => 'demo_activa'], 201);
    }

    public function checkout(Request $request)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'payment_provider' => ['nullable', 'string', 'max:100'],
            'success_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);
        $amount = (float) ($plan->amount ?: $plan->price_monthly ?: $plan->price ?: 0);
        abort_if($amount <= 0, 422, 'El plan no tiene un monto valido para cobrar.');

        $user = $request->user();
        $user->subscriptions()->where('status', 'active')->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $suscripcion = Suscripcion::create([
            'user_id' => $request->user()->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
            'payment_status' => 'pending',
            'payment_provider' => $data['payment_provider'] ?? 'stripe',
        ]);

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $successUrl = $data['success_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/perfil?subscription=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $data['cancel_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/perfil?subscription=cancelled&session_id={CHECKOUT_SESSION_ID}';

        $metadata = [
            'billing_context' => 'client_subscription',
            'user_id' => (string) $user->id,
            'subscription_record_id' => (string) $suscripcion->id,
            'plan_id' => (string) $plan->id,
            'plan_code' => (string) ($plan->code ?: ''),
        ];

        $lineItem = $plan->stripe_price_id
            ? [
                'price' => $plan->stripe_price_id,
                'quantity' => 1,
            ]
            : [
                'price_data' => [
                    'currency' => strtolower((string) ($plan->currency ?: 'USD')),
                    'product_data' => [
                        'name' => $plan->name,
                        'description' => $plan->description,
                    ],
                    'unit_amount' => (int) round($amount * 100),
                    'recurring' => [
                        'interval' => $plan->billing_cycle === 'yearly' ? 'year' : 'month',
                    ],
                ],
                'quantity' => 1,
            ];

        $session = Session::create([
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $user->email,
            'client_reference_id' => (string) $suscripcion->id,
            'metadata' => $metadata,
            'line_items' => [$lineItem],
            'subscription_data' => [
                'metadata' => $metadata,
            ],
        ]);

        DB::transaction(function () use ($suscripcion, $session, $amount, $plan, $user) {
            Pago::create([
                'user_id' => $user->id,
                'subscription_id' => $suscripcion->id,
                'payment_type' => 'subscription',
                'amount' => $amount,
                'currency' => strtoupper((string) ($plan->currency ?: 'USD')),
                'provider' => 'stripe',
                'transaction_reference' => $session->id,
                'stripe_checkout_session_id' => $session->id,
                'status' => 'pending',
                'gateway_response' => [
                    'source' => 'subscription_checkout_created',
                    'checkout_session_id' => $session->id,
                    'checkout_url' => $session->url,
                    'subscription_record_id' => $suscripcion->id,
                    'plan_id' => $plan->id,
                    'plan_code' => $plan->code,
                    'stripe_payload' => json_decode(json_encode($session), true),
                ],
            ]);
        });

        return $this->ok([
            'subscription' => $suscripcion->fresh('plan'),
            'checkout_url' => $session->url,
            'checkout_session_id' => $session->id,
        ], 201);
    }

    public function current(Request $request)
    {
        return $this->ok([
            'subscription' => $request->user()->activeSuscripcion?->load('plan'),
            'access' => $request->user()->accessStatus(),
        ]);
    }

    public function cancel(Request $request)
    {
        $suscripcion = $request->user()->activeSuscripcion;
        abort_if(! $suscripcion, 404, 'No existe una suscripcion activa.');

        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        abort_if(! $suscripcion->provider_subscription_id, 409, 'La suscripcion activa no tiene una referencia valida en Stripe.');

        Stripe::setApiKey((string) config('services.stripe.secret'));
        $stripeSubscription = StripeSubscription::cancel($suscripcion->provider_subscription_id, []);

        DB::transaction(function () use ($suscripcion, $stripeSubscription) {
            $suscripcion->update([
                'status' => 'cancelled',
                'payment_status' => 'cancelled',
                'cancelled_at' => now(),
                'ends_at' => ! empty($stripeSubscription->ended_at) ? now()->setTimestamp((int) $stripeSubscription->ended_at) : $suscripcion->ends_at,
                'expires_at' => ! empty($stripeSubscription->ended_at) ? now()->setTimestamp((int) $stripeSubscription->ended_at) : $suscripcion->expires_at,
                'renews_at' => null,
            ]);

            Pago::create([
                'user_id' => $suscripcion->user_id,
                'subscription_id' => $suscripcion->id,
                'payment_type' => 'subscription',
                'amount' => 0,
                'currency' => 'USD',
                'provider' => 'stripe',
                'transaction_reference' => (string) ($stripeSubscription->id ?? $suscripcion->provider_subscription_id),
                'status' => 'cancelled',
                'failure_reason' => 'Suscripcion cancelada desde el portal.',
                'gateway_response' => [
                    'source' => 'subscription_cancelled',
                    'stripe_payload' => json_decode(json_encode($stripeSubscription), true),
                ],
            ]);
        });

        return $this->ok(['subscription' => $suscripcion->fresh('plan')]);
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
