<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\AccessPayment;
use App\Modelos\Usuario;
use App\Servicios\Billing\BillingPlanServicio;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session;
use Stripe\Invoice;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

class ClientAccessBillingControlador extends ControladorBase
{
    public function __construct(private readonly BillingPlanServicio $billingPlanServicio)
    {
    }

    public function status(Request $request)
    {
        $user = $request->user()->fresh(['demo', 'activeSuscripcion.plan']);
        $latestPayment = $this->latestPaymentForUser($user);

        return $this->ok([
            'access' => $this->buildAccessPayload($user, $latestPayment),
            'latest_payment' => $latestPayment,
        ]);
    }

    public function create(Request $request)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $user = $request->user()->fresh();
        $data = $request->validate([
            'success_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
            'return_url' => ['nullable', 'url'],
            'contact_email' => ['nullable', 'email:rfc,dns'],
        ]);

        if ($portal = $this->createBillingPortalIfAvailable($user, $data)) {
            return $this->ok($portal);
        }

        if ($this->hasBlockingActiveAccess($user)) {
            return response()->json([
                'success' => false,
                'message' => 'El cliente ya cuenta con una suscripcion comercial activa fuera de la ventana de renovacion.',
            ], 409);
        }

        $plan = $this->billingPlanServicio->findActiveByCode(BillingPlanServicio::CLIENT_ACCESS_CODE);
        abort_if(! $plan, 404, 'No encontramos el plan de acceso cliente.');

        $amount = (float) ($plan->amount ?: $plan->price ?: 0);
        abort_if($amount <= 0, 422, 'El plan de acceso cliente no tiene un monto valido.');

        Stripe::setApiKey((string) config('services.stripe.secret'));

        [$periodStart, $periodEnd] = $this->resolveMonthlyAccessPeriod();
        $payment = $this->createPendingPayment(
            userId: $user->id,
            planId: $plan->id,
            amount: $amount,
            currency: strtoupper((string) ($plan->currency ?: 'USD')),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );

        $successUrl = $data['success_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/pago?accessPayment=1&checkout=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $data['cancel_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/pago?accessPayment=1&checkout=cancelled&session_id={CHECKOUT_SESSION_ID}';

        $metadata = [
            'billing_context' => 'client_access_subscription',
            'user_id' => (string) $user->id,
            'access_payment_id' => (string) $payment->id,
            'billing_plan_id' => (string) $plan->id,
            'plan_code' => (string) $plan->code,
        ];

        $sessionPayload = [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $payment->id,
            'metadata' => $metadata,
            'line_items' => [$this->buildCheckoutLineItem($plan, $amount)],
            'subscription_data' => [
                'metadata' => $metadata,
            ],
        ];

        if ($user->provider_customer_id) {
            $sessionPayload['customer'] = (string) $user->provider_customer_id;
        } else {
            $sessionPayload['customer_email'] = $data['contact_email'] ?? $user->email;
        }

        $session = Session::create($sessionPayload);

        $payment->update([
            'provider_checkout_id' => $session->id,
            'gateway_response' => [
                'source' => 'client_access_subscription_checkout_created',
                'checkout_url' => $session->url,
                'stripe_payload' => json_decode(json_encode($session), true),
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
        return response()->json([
            'success' => false,
            'message' => 'El acceso comercial ahora usa Checkout de suscripcion. Utiliza /client/access-payment/create.',
        ], 409);
    }

    public function confirmPaymentIntent(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'La confirmacion manual por PaymentIntent ya no aplica al acceso comercial recurrente.',
        ], 409);
    }

    public function success(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:255'],
            'checkout_session_id' => ['nullable', 'string', 'max:255'],
        ]);

        $sessionId = $data['session_id'] ?? $data['checkout_session_id'] ?? null;
        $user = $request->user()->fresh();
        $payment = AccessPayment::query()
            ->with('billingPlan:id,code,name,amount,currency')
            ->where('user_id', $user->id)
            ->when($sessionId, fn ($query) => $query->where('provider_checkout_id', $sessionId))
            ->latest('id')
            ->first();

        abort_if(! $payment, 404, 'No encontramos el pago de acceso solicitado.');

        if ($sessionId || $payment->provider_checkout_id) {
            if ($response = $this->ensureStripeIsConfigured()) {
                return $response;
            }

            $payment = $this->syncCheckoutSubscriptionStatus(
                $payment,
                (string) ($sessionId ?: $payment->provider_checkout_id),
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
        $user = $request->user()->fresh();
        $payment = AccessPayment::query()
            ->where('user_id', $user->id)
            ->when($sessionId, fn ($query) => $query->where('provider_checkout_id', $sessionId))
            ->latest('id')
            ->first();

        if ($payment && $payment->status === 'pending') {
            $payment->update(['status' => 'cancelled']);
        }

        DB::table('users')->where('id', $user->id)->update([
            'access_status' => $this->resolveCancelledStatus($user),
            'updated_at' => now(),
        ]);

        return $this->ok([
            'message' => 'Flujo de pago de acceso cancelado.',
            'access' => $this->buildAccessPayload($request->user()->fresh()),
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

    private function createBillingPortalIfAvailable(Usuario $user, array $data): ?array
    {
        $manageableStatuses = ['active', 'past_due', 'suspended', 'unpaid'];
        if (! $user->provider_customer_id || ! in_array((string) $user->access_status, $manageableStatuses, true)) {
            return null;
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));
        $returnUrl = $data['return_url']
            ?? $data['success_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/perfil';

        $session = BillingPortalSession::create([
            'customer' => (string) $user->provider_customer_id,
            'return_url' => $returnUrl,
        ]);

        return [
            'management_url' => $session->url,
            'portal_session_id' => $session->id,
            'message' => 'El cliente ya tiene una suscripcion creada. Se genero una sesion del portal de facturacion para administrar su metodo de pago.',
        ];
    }

    private function buildCheckoutLineItem($plan, float $amount): array
    {
        if ($plan->stripe_price_id) {
            return [
                'price' => $plan->stripe_price_id,
                'quantity' => 1,
            ];
        }

        return [
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

    private function buildAccessPayload(Usuario $user, ?AccessPayment $latestPayment = null): array
    {
        $access = $user->accessStatus()['commercial_access'];

        return array_merge($access, [
            'latest_payment' => $latestPayment ?? $this->latestPaymentForUser($user),
        ]);
    }

    private function latestPaymentForUser(Usuario $user): ?AccessPayment
    {
        return AccessPayment::query()
            ->with('billingPlan:id,code,name,amount,currency')
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    private function syncCheckoutSubscriptionStatus(AccessPayment $payment, string $sessionId): AccessPayment
    {
        $targetSessionId = trim($sessionId);
        if ($targetSessionId === '') {
            return $payment->fresh('billingPlan');
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));
        $session = Session::retrieve($targetSessionId);
        abort_if(! $session, 404, 'Stripe no devolvio informacion de la sesion de Checkout.');

        $sessionMetadata = (array) ($session->metadata ?? []);
        $billingContext = (string) ($sessionMetadata['billing_context'] ?? '');
        if ($billingContext !== '' && $billingContext !== 'client_access_subscription') {
            abort(409, 'La sesion de Checkout no corresponde al acceso comercial recurrente.');
        }

        $subscriptionId = (string) ($session->subscription ?? '');
        $subscription = $subscriptionId !== '' ? StripeSubscription::retrieve($subscriptionId) : null;
        $invoicePayload = null;
        $invoiceId = (string) ($session->invoice ?? '');
        if ($invoiceId !== '') {
            $invoicePayload = Invoice::retrieve($invoiceId);
        }

        $status = (string) ($subscription->status ?? $session->status ?? 'pending');
        $periodStart = ! empty($subscription?->current_period_start)
            ? Carbon::createFromTimestamp((int) $subscription->current_period_start)->startOfDay()
            : ($payment->billing_period_start ? Carbon::parse($payment->billing_period_start)->startOfDay() : now()->startOfDay());
        $periodEnd = ! empty($subscription?->current_period_end)
            ? Carbon::createFromTimestamp((int) $subscription->current_period_end)->endOfDay()
            : ($payment->billing_period_end ? Carbon::parse($payment->billing_period_end)->endOfDay() : now()->addMonthNoOverflow()->endOfDay());

        $payload = [
            'checkout_session' => json_decode(json_encode($session), true),
            'subscription' => $subscription ? json_decode(json_encode($subscription), true) : null,
            'invoice' => $invoicePayload ? json_decode(json_encode($invoicePayload), true) : null,
        ];

        DB::transaction(function () use ($payment, $session, $subscription, $invoicePayload, $status, $periodStart, $periodEnd, $payload) {
            $customerId = (string) ($session->customer ?? $subscription?->customer ?? $payment->provider_customer_id ?? '');
            $subscriptionId = (string) ($subscription?->id ?? $session->subscription ?? $payment->provider_subscription_id ?? '');
            $invoiceId = (string) ($invoicePayload?->id ?? $session->invoice ?? $payment->provider_invoice_id ?? '');
            $paymentIntentId = (string) ($invoicePayload?->payment_intent ?? $session->payment_intent ?? $payment->provider_payment_id ?? '');

            $payment->update([
                'status' => in_array($status, ['active', 'trialing'], true) ? 'paid' : $payment->status,
                'provider_checkout_id' => (string) $session->id,
                'provider_customer_id' => $customerId !== '' ? $customerId : $payment->provider_customer_id,
                'provider_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $payment->provider_subscription_id,
                'provider_invoice_id' => $invoiceId !== '' ? $invoiceId : $payment->provider_invoice_id,
                'provider_payment_id' => $paymentIntentId !== '' ? $paymentIntentId : $payment->provider_payment_id,
                'billing_period_start' => $periodStart->toDateString(),
                'billing_period_end' => $periodEnd->toDateString(),
                'paid_at' => in_array($status, ['active', 'trialing'], true) ? ($payment->paid_at ?: now()) : $payment->paid_at,
                'gateway_response' => $payload,
            ]);

            if (in_array($status, ['active', 'trialing'], true)) {
                DB::table('users')->where('id', $payment->user_id)->update([
                    'access_status' => 'active',
                    'has_paid_access' => true,
                    'paid_access_at' => now(),
                    'access_expires_at' => $periodEnd,
                    'grace_period_ends_at' => null,
                    'next_retry_at' => null,
                    'provider_subscription_id' => $subscriptionId !== '' ? $subscriptionId : DB::raw('provider_subscription_id'),
                    'provider_customer_id' => $customerId !== '' ? $customerId : DB::raw('provider_customer_id'),
                    'access_payment_id' => $payment->id,
                    'updated_at' => now(),
                ]);
            }
        });

        return $payment->fresh('billingPlan');
    }

    private function resolveCancelledStatus(Usuario $user): string
    {
        if ($user->has_paid_access && in_array((string) $user->access_status, ['active', 'past_due', 'suspended', 'unpaid'], true)) {
            return (string) $user->access_status;
        }

        $trialEndsAt = $user->trial_ends_at ? Carbon::parse($user->trial_ends_at) : null;
        $trialStillActive = ! $trialEndsAt || $trialEndsAt->isFuture();
        $freeQuoteLimit = max(1, (int) ($user->free_quote_limit ?? 1));
        $freeQuotesUsed = max(0, (int) ($user->free_quotes_used ?? 0));

        return $trialStillActive && $freeQuotesUsed < $freeQuoteLimit ? 'trial_active' : 'trial_used';
    }

    private function hasBlockingActiveAccess(Usuario $user): bool
    {
        if (! $user->has_paid_access || $user->access_status !== 'active' || ! $user->access_expires_at) {
            return false;
        }

        $expiresAt = Carbon::parse($user->access_expires_at);
        $renewalWindowStart = now()->copy()->addDays(7)->startOfDay();

        return $expiresAt->greaterThan($renewalWindowStart);
    }
}
