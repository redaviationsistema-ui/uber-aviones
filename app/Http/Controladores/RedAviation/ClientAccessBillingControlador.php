<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\AccessPayment;
use App\Servicios\Billing\BillingPlanServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class ClientAccessBillingControlador extends ControladorBase
{
    public function __construct(private readonly BillingPlanServicio $billingPlanServicio)
    {
    }

    public function status(Request $request)
    {
        $user = $request->user();
        $latestPayment = AccessPayment::query()
            ->with('billingPlan:id,code,name,amount,currency')
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return $this->ok([
            'access' => [
                'status' => $user->access_status ?: 'trial_active',
                'trial_started_at' => $user->trial_started_at,
                'trial_ends_at' => $user->trial_ends_at,
                'free_quote_limit' => (int) ($user->free_quote_limit ?? 1),
                'free_quotes_used' => (int) ($user->free_quotes_used ?? 0),
                'has_paid_access' => (bool) $user->has_paid_access,
                'paid_access_at' => $user->paid_access_at,
                'access_payment_id' => $user->access_payment_id,
            ],
            'latest_payment' => $latestPayment,
        ]);
    }

    public function create(Request $request)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $user = $request->user();
        if ($user->has_paid_access && $user->access_status === 'active') {
            return response()->json([
                'success' => false,
                'message' => 'El cliente ya cuenta con acceso pagado activo.',
            ], 409);
        }

        $data = $request->validate([
            'success_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
        ]);

        $plan = $this->billingPlanServicio->findActiveByCode(BillingPlanServicio::CLIENT_ACCESS_CODE);
        abort_if(! $plan, 404, 'No encontramos el plan de acceso cliente.');

        $amount = (float) ($plan->amount ?: $plan->price ?: 0);
        abort_if($amount <= 0, 422, 'El plan de acceso cliente no tiene un monto valido.');

        $payment = AccessPayment::create([
            'user_id' => $user->id,
            'billing_plan_id' => $plan->id,
            'amount' => $amount,
            'currency' => strtoupper((string) ($plan->currency ?: 'USD')),
            'status' => 'pending',
            'provider' => 'stripe',
        ]);

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $successUrl = $data['success_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/acceso?checkout=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $data['cancel_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/acceso?checkout=cancelled&session_id={CHECKOUT_SESSION_ID}';

        $session = Session::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'customer_email' => $user->email,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $payment->id,
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower((string) ($plan->currency ?: 'USD')),
                    'product_data' => [
                        'name' => $plan->name,
                        'description' => $plan->description,
                    ],
                    'unit_amount' => (int) round($amount * 100),
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'billing_context' => 'client_access',
                'user_id' => (string) $user->id,
                'access_payment_id' => (string) $payment->id,
                'billing_plan_id' => (string) $plan->id,
                'plan_code' => (string) $plan->code,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'billing_context' => 'client_access',
                    'user_id' => (string) $user->id,
                    'access_payment_id' => (string) $payment->id,
                    'billing_plan_id' => (string) $plan->id,
                    'plan_code' => (string) $plan->code,
                ],
            ],
        ]);

        $payment->update([
            'provider_checkout_id' => $session->id,
            'gateway_response' => [
                'checkout_url' => $session->url,
            ],
        ]);

        DB::table('users')->where('id', $user->id)->update([
            'access_status' => 'payment_pending',
            'access_payment_id' => $payment->id,
            'updated_at' => now(),
        ]);

        return $this->ok([
            'payment' => $payment->fresh('billingPlan'),
            'checkout_url' => $session->url,
            'checkout_session_id' => $session->id,
        ], 201);
    }

    public function success(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:255'],
            'checkout_session_id' => ['nullable', 'string', 'max:255'],
        ]);

        $sessionId = $data['session_id'] ?? $data['checkout_session_id'] ?? null;
        $payment = AccessPayment::query()
            ->with('billingPlan:id,code,name,amount,currency')
            ->where('user_id', $request->user()->id)
            ->when($sessionId, fn ($query) => $query->where('provider_checkout_id', $sessionId))
            ->latest('id')
            ->first();

        abort_if(! $payment, 404, 'No encontramos el pago de acceso solicitado.');

        return $this->ok([
            'payment' => $payment,
            'access' => [
                'status' => $request->user()->fresh()->access_status,
                'has_paid_access' => (bool) $request->user()->fresh()->has_paid_access,
                'paid_access_at' => $request->user()->fresh()->paid_access_at,
            ],
        ]);
    }

    public function cancel(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:255'],
            'checkout_session_id' => ['nullable', 'string', 'max:255'],
        ]);

        $sessionId = $data['session_id'] ?? $data['checkout_session_id'] ?? null;
        $payment = AccessPayment::query()
            ->where('user_id', $request->user()->id)
            ->when($sessionId, fn ($query) => $query->where('provider_checkout_id', $sessionId))
            ->latest('id')
            ->first();

        if ($payment && $payment->status === 'pending') {
            $payment->update(['status' => 'cancelled']);
        }

        DB::table('users')->where('id', $request->user()->id)->update([
            'access_status' => DB::raw("case when has_paid_access = true then 'active' else 'trial_active' end"),
            'updated_at' => now(),
        ]);

        return $this->ok([
            'message' => 'Pago de acceso cancelado.',
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
