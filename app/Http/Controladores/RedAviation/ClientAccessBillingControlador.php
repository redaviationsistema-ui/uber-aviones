<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\AccessPayment;
use App\Servicios\Billing\BillingPlanServicio;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
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
                'access_expires_at' => $user->access_expires_at,
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
            'contact_email' => ['nullable', 'email:rfc,dns'],
        ]);

        $plan = $this->billingPlanServicio->findActiveByCode(BillingPlanServicio::CLIENT_ACCESS_CODE);
        abort_if(! $plan, 404, 'No encontramos el plan de acceso cliente.');

        $amount = (float) ($plan->amount ?: $plan->price ?: 0);
        abort_if($amount <= 0, 422, 'El plan de acceso cliente no tiene un monto valido.');

        [$periodStart, $periodEnd] = $this->resolveMonthlyAccessPeriod();
        $payment = $this->createPendingPayment($user->id, $plan->id, $amount, strtoupper((string) ($plan->currency ?: 'USD')), $periodStart, $periodEnd);

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $successUrl = $data['success_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/pago?accessPayment=1&checkout=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $data['cancel_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/pago?accessPayment=1&checkout=cancelled&session_id={CHECKOUT_SESSION_ID}';

        $session = Session::create([
            'mode' => 'payment',
            'customer_email' => $data['contact_email'] ?? $user->email,
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

    public function createPaymentIntent(Request $request)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $user = $request->user();
        if ($user->has_paid_access && $user->access_status === 'active' && (! $user->access_expires_at || Carbon::parse($user->access_expires_at)->isFuture())) {
            return response()->json([
                'success' => false,
                'message' => 'El cliente ya cuenta con acceso pagado activo.',
            ], 409);
        }

        $data = $request->validate([
            'contact_email' => ['nullable', 'email:rfc,dns'],
        ]);

        $plan = $this->billingPlanServicio->findActiveByCode(BillingPlanServicio::CLIENT_ACCESS_CODE);
        abort_if(! $plan, 404, 'No encontramos el plan de acceso cliente.');

        $amount = (float) ($plan->amount ?: $plan->price ?: 0);
        abort_if($amount <= 0, 422, 'El plan de acceso cliente no tiene un monto valido.');

        [$periodStart, $periodEnd] = $this->resolveMonthlyAccessPeriod();
        $payment = $this->createPendingPayment($user->id, $plan->id, $amount, strtoupper((string) ($plan->currency ?: 'USD')), $periodStart, $periodEnd);

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $paymentIntent = PaymentIntent::create([
            'amount' => (int) round($amount * 100),
            'currency' => strtolower((string) ($plan->currency ?: 'USD')),
            'receipt_email' => $data['contact_email'] ?? $user->email,
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
            'metadata' => [
                'billing_context' => 'client_access',
                'user_id' => (string) $user->id,
                'access_payment_id' => (string) $payment->id,
                'billing_plan_id' => (string) $plan->id,
                'plan_code' => (string) $plan->code,
                'billing_period_start' => $periodStart->toDateString(),
                'billing_period_end' => $periodEnd->toDateString(),
            ],
        ]);

        $payment->update([
            'provider_payment_id' => $paymentIntent->id,
            'gateway_response' => [
                'payment_intent_id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
            ],
        ]);

        DB::table('users')->where('id', $user->id)->update([
            'access_status' => 'payment_pending',
            'access_payment_id' => $payment->id,
            'updated_at' => now(),
        ]);

        return $this->ok([
            'payment' => $payment->fresh('billingPlan'),
            'payment_intent_id' => $paymentIntent->id,
            'client_secret' => $paymentIntent->client_secret,
            'publishable_key' => config('services.stripe.publishable'),
        ], 201);
    }

    public function confirmPaymentIntent(Request $request)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $user = $request->user();
        $data = $request->validate([
            'payment_intent_id' => ['required', 'string', 'max:255'],
        ]);

        Stripe::setApiKey((string) config('services.stripe.secret'));
        $paymentIntent = PaymentIntent::retrieve($data['payment_intent_id']);

        abort_if(! $paymentIntent, 404, 'Stripe no devolvio informacion del PaymentIntent.');

        $metadata = (array) ($paymentIntent->metadata ?? []);
        abort_if(($metadata['billing_context'] ?? '') !== 'client_access', 409, 'El PaymentIntent no corresponde al acceso comercial.');

        $accessPaymentId = (int) ($metadata['access_payment_id'] ?? 0);
        $payment = AccessPayment::query()
            ->where('id', $accessPaymentId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $freshUser = $user->fresh();
        $paymentAlreadyApplied = $payment->status === 'paid'
            && $payment->provider_payment_id === $paymentIntent->id
            && (bool) $freshUser?->has_paid_access
            && $freshUser?->access_status === 'active';

        if (($paymentIntent->status ?? '') !== 'succeeded') {
            if ($paymentAlreadyApplied) {
                return $this->ok([
                    'payment' => $payment->fresh('billingPlan'),
                    'access' => $this->buildAccessPayload($freshUser),
                ]);
            }

            abort(409, 'Stripe aun no confirma este pago como exitoso.');
        }

        if ($paymentAlreadyApplied) {
            return $this->ok([
                'payment' => $payment->fresh('billingPlan'),
                'access' => $this->buildAccessPayload($freshUser),
            ]);
        }

        [$periodStart, $periodEnd] = $this->resolvePaymentPeriodFromMetadata($metadata);
        $brand = (string) (
            data_get($paymentIntent, 'payment_method_details.card.brand')
            ?? data_get($paymentIntent, 'charges.data.0.payment_method_details.card.brand')
            ?? ''
        );
        $last4 = (string) (
            data_get($paymentIntent, 'payment_method_details.card.last4')
            ?? data_get($paymentIntent, 'charges.data.0.payment_method_details.card.last4')
            ?? ''
        );

        DB::transaction(function () use ($payment, $paymentIntent, $brand, $last4, $periodStart, $periodEnd) {
            $payment->update([
                'status' => 'paid',
                'provider_payment_id' => $paymentIntent->id,
                'card_brand' => $brand !== '' ? $brand : $payment->card_brand,
                'card_last4' => $last4 !== '' ? $last4 : $payment->card_last4,
                'paid_at' => now(),
                'billing_period_start' => $periodStart->toDateString(),
                'billing_period_end' => $periodEnd->toDateString(),
                'gateway_response' => json_decode(json_encode($paymentIntent), true),
            ]);

            DB::table('users')->where('id', $payment->user_id)->update([
                'access_status' => 'active',
                'has_paid_access' => true,
                'paid_access_at' => now(),
                'access_expires_at' => $periodEnd->copy()->endOfDay(),
                'access_payment_id' => $payment->id,
                'updated_at' => now(),
            ]);
        });

        $freshUser = $user->fresh();

        return $this->ok([
            'payment' => $payment->fresh('billingPlan'),
            'access' => $this->buildAccessPayload($freshUser),
        ]);
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

        if (! $this->ensureStripeIsConfigured() && ($sessionId || $payment->provider_checkout_id)) {
            $payment = $this->syncCheckoutPaymentStatus(
                $payment,
                $sessionId ?: (string) $payment->provider_checkout_id,
            );
        }

        $freshUser = $request->user()->fresh();

        return $this->ok([
            'payment' => $payment->fresh('billingPlan'),
            'access' => $this->buildAccessPayload($freshUser),
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
            'access_status' => DB::raw("
                case
                    when has_paid_access = true then 'active'
                    when coalesce(free_quotes_used, 0) >= greatest(coalesce(free_quote_limit, 1), 1) then 'trial_used'
                    else 'trial_active'
                end
            "),
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

    private function createPendingPayment(int $userId, int $planId, float $amount, string $currency, Carbon $periodStart, Carbon $periodEnd): AccessPayment
    {
        return AccessPayment::create([
            'user_id' => $userId,
            'billing_plan_id' => $planId,
            'amount' => $amount,
            'currency' => $currency,
            'billing_period_start' => $periodStart->toDateString(),
            'billing_period_end' => $periodEnd->toDateString(),
            'status' => 'pending',
            'provider' => 'stripe',
        ]);
    }

    private function resolveMonthlyAccessPeriod(): array
    {
        $start = now();
        $end = $start->copy()->addMonthNoOverflow();

        return [$start, $end];
    }

    private function resolvePaymentPeriodFromMetadata(array $metadata): array
    {
        $start = ! empty($metadata['billing_period_start'])
            ? Carbon::parse((string) $metadata['billing_period_start'])
            : now();
        $end = ! empty($metadata['billing_period_end'])
            ? Carbon::parse((string) $metadata['billing_period_end'])
            : $start->copy()->addMonthNoOverflow();

        return [$start, $end];
    }

    private function buildAccessPayload($user): array
    {
        return [
            'status' => $user->access_status,
            'has_paid_access' => (bool) $user->has_paid_access,
            'paid_access_at' => $user->paid_access_at,
            'access_expires_at' => $user->access_expires_at,
        ];
    }

    private function syncCheckoutPaymentStatus(AccessPayment $payment, string $sessionId = ''): AccessPayment
    {
        $targetSessionId = trim($sessionId) !== '' ? trim($sessionId) : (string) $payment->provider_checkout_id;
        if ($targetSessionId === '') {
            return $payment->fresh('billingPlan');
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));
        $session = Session::retrieve($targetSessionId);

        abort_if(! $session, 404, 'Stripe no devolvio informacion de la sesion de Checkout.');

        $sessionMetadata = (array) ($session->metadata ?? []);
        $billingContext = (string) ($sessionMetadata['billing_context'] ?? '');
        if ($billingContext !== '' && $billingContext !== 'client_access') {
            abort(409, 'La sesion de Checkout no corresponde al acceso comercial.');
        }

        $paymentStatus = strtolower((string) ($session->payment_status ?? ''));
        if ($paymentStatus !== 'paid') {
            return $payment->fresh('billingPlan');
        }

        $paymentIntentId = (string) ($session->payment_intent ?? $payment->provider_payment_id ?? '');
        $paymentIntent = $paymentIntentId !== '' ? PaymentIntent::retrieve($paymentIntentId) : null;
        $intentMetadata = (array) ($paymentIntent->metadata ?? []);
        $metadata = ! empty($intentMetadata) ? $intentMetadata : $sessionMetadata;
        [$periodStart, $periodEnd] = $this->resolvePaymentPeriodFromMetadata($metadata);

        $brand = (string) (
            data_get($paymentIntent, 'payment_method_details.card.brand')
            ?? data_get($paymentIntent, 'charges.data.0.payment_method_details.card.brand')
            ?? ''
        );
        $last4 = (string) (
            data_get($paymentIntent, 'payment_method_details.card.last4')
            ?? data_get($paymentIntent, 'charges.data.0.payment_method_details.card.last4')
            ?? ''
        );

        DB::transaction(function () use (
            $payment,
            $paymentIntentId,
            $targetSessionId,
            $paymentIntent,
            $session,
            $brand,
            $last4,
            $periodStart,
            $periodEnd
        ) {
            $payment->update([
                'status' => 'paid',
                'provider_payment_id' => $paymentIntentId !== '' ? $paymentIntentId : $payment->provider_payment_id,
                'provider_checkout_id' => $targetSessionId,
                'card_brand' => $brand !== '' ? $brand : $payment->card_brand,
                'card_last4' => $last4 !== '' ? $last4 : $payment->card_last4,
                'paid_at' => $payment->paid_at ?: now(),
                'billing_period_start' => $periodStart->toDateString(),
                'billing_period_end' => $periodEnd->toDateString(),
                'gateway_response' => [
                    'checkout_session' => json_decode(json_encode($session), true),
                    'payment_intent' => $paymentIntent ? json_decode(json_encode($paymentIntent), true) : null,
                ],
            ]);

            DB::table('users')->where('id', $payment->user_id)->update([
                'access_status' => 'active',
                'has_paid_access' => true,
                'paid_access_at' => DB::raw('coalesce(paid_access_at, now())'),
                'access_expires_at' => $periodEnd->copy()->endOfDay(),
                'access_payment_id' => $payment->id,
                'updated_at' => now(),
            ]);
        });

        return $payment->fresh('billingPlan');
    }
}
