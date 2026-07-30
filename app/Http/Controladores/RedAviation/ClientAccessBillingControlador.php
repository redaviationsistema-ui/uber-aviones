<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\AccessPayment;
use App\Modelos\Usuario;
use App\Servicios\Billing\BillingPlanServicio;
use App\Servicios\Pagos\PaymentFeeCalculationServicio;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session;
use Stripe\Invoice;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

class ClientAccessBillingControlador extends ControladorBase
{
    public function __construct(
        private readonly BillingPlanServicio $billingPlanServicio,
        private readonly PaymentFeeCalculationServicio $paymentFeeCalculationServicio,
    ) {
    }

    public function status(Request $request)
    {
        $user = $request->user()->fresh(['demo', 'activeSuscripcion.plan']);
        $latestPayment = $this->latestPaymentForUser($user);
        if ($latestPayment && $this->paymentCanBeSyncedFromCheckout($latestPayment)) {
            try {
                $latestPayment = $this->syncCheckoutSubscriptionStatus(
                    $latestPayment,
                    (string) $latestPayment->provider_checkout_id,
                );
                $user = $request->user()->fresh(['demo', 'activeSuscripcion.plan']);
            } catch (\Throwable) {
                // El estado debe seguir respondiendo aun si Stripe no esta disponible.
            }
        }

        $paymentPreview = $this->resolveAccessPaymentPreview();

        return $this->ok([
            'access' => $this->buildAccessPayload($user, $latestPayment, $paymentPreview),
            'latest_payment' => $this->serializeAccessPayment($latestPayment),
            'payment_preview' => $paymentPreview,
        ]);
    }

    public function create(Request $request)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $user = $request->user()->fresh();
        $data = $request->validate([
            'intent' => ['nullable', 'string', 'in:checkout,manage'],
            'success_url' => ['nullable', 'string', 'max:2048'],
            'cancel_url' => ['nullable', 'string', 'max:2048'],
            'return_url' => ['nullable', 'string', 'max:2048'],
            'contact_email' => ['nullable', 'string', 'max:255'],
        ]);
        $intent = strtolower((string) ($data['intent'] ?? 'checkout'));
        $contactEmail = $request->input('contact_email')
            ?: $request->user()?->email;

        Validator::make(
            ['contact_email' => $contactEmail],
            [
                'contact_email' => ['required', 'email:rfc'],
            ]
        )->validate();

        $data['contact_email'] = (string) $contactEmail;
        $this->assertAllowedReturnUrl($data['success_url'] ?? null);
        $this->assertAllowedReturnUrl($data['cancel_url'] ?? null);
        $this->assertAllowedReturnUrl($data['return_url'] ?? null);

        if ($intent === 'manage' && ($portal = $this->createBillingPortalIfAvailable($user, $data))) {
            return $this->ok($portal);
        }

        if ($this->hasBlockingActiveAccess($user) || $this->hasAnyActiveAccess($user)) {
            return $this->ok([
                'already_active' => true,
                'success' => true,
                'message' => 'El cliente ya cuenta con una suscripcion comercial activa.',
                'access' => $this->buildAccessPayload($user),
                'latest_payment' => $this->serializeAccessPayment($this->latestPaymentForUser($user)),
            ]);
        }

        if ($intent !== 'manage' && $this->requiresBillingPortalManagement($user)) {
            return $this->ok([
                'success' => true,
                'management_required' => true,
                'message' => 'La suscripcion existente debe administrarse desde Facturacion o Metodo de pago.',
                'access' => $this->buildAccessPayload($user),
                'latest_payment' => $this->serializeAccessPayment($this->latestPaymentForUser($user)),
            ]);
        }

        $latestPayment = $this->latestPaymentForUser($user);
        if ($latestPayment && $this->paymentCanBeSyncedFromCheckout($latestPayment)) {
            try {
                $latestPayment = $this->syncCheckoutSubscriptionStatus(
                    $latestPayment,
                    (string) $latestPayment->provider_checkout_id,
                );
                $user = $request->user()->fresh();
            } catch (\Throwable) {
                // Si Stripe no responde, seguimos con el snapshot local.
            }
        }

        $accessPayload = $this->buildAccessPayload($user, $latestPayment);
        $existingCheckoutUrl = trim((string) data_get($latestPayment?->gateway_response, 'checkout_url', ''));
        if (
            $latestPayment &&
            in_array((string) $latestPayment->status, ['pending', 'processing', 'payment_pending'], true) &&
            trim((string) $latestPayment->provider_checkout_id) !== '' &&
            $this->isStripeHostedUrl($existingCheckoutUrl) &&
            $this->checkoutCanBeReused($accessPayload)
        ) {
            return $this->ok([
                'success' => true,
                'reused_checkout' => true,
                'access_status' => $accessPayload['status'] ?? null,
                'access' => $accessPayload,
                'payment' => $this->serializeAccessPayment($latestPayment),
                'checkout_url' => $existingCheckoutUrl,
                'checkoutUrl' => $existingCheckoutUrl,
                'checkout_session_id' => $latestPayment->provider_checkout_id,
                'checkoutSessionId' => $latestPayment->provider_checkout_id,
            ]);
        }

        $plan = $this->billingPlanServicio->findActiveByCode(BillingPlanServicio::CLIENT_ACCESS_CODE);
        abort_if(! $plan, 404, 'No encontramos el plan de acceso cliente.');

        $baseAmount = (float) ($plan->amount ?: $plan->price ?: 0);
        abort_if($baseAmount <= 0, 422, 'El plan de acceso cliente no tiene un monto valido.');

        $pricing = $this->paymentFeeCalculationServicio->membershipBreakdown($baseAmount);
        $amount = (float) $pricing['total_amount'];

        Stripe::setApiKey((string) config('services.stripe.secret'));

        [$periodStart, $periodEnd] = $this->resolveMonthlyAccessPeriod();
        $payment = $this->createPendingPayment(
            userId: $user->id,
            planId: $plan->id,
            amount: $amount,
            currency: strtoupper((string) ($plan->currency ?: 'USD')),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            pricing: $pricing,
        );

        $successUrl = $data['success_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/pago?checkout=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $data['cancel_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/pago?checkout=cancelled';

        $metadata = [
            'billing_context' => 'client_access_subscription',
            'user_id' => (string) $user->id,
            'access_payment_id' => (string) $payment->id,
            'billing_plan_id' => (string) $plan->id,
            'plan_code' => (string) $plan->code,
            'payment_type' => 'commercial_access',
            'purpose' => 'account_activation',
            'access_payment' => 'true',
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
                'pricing' => $pricing,
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

        $freshUser = $request->user()->fresh();
        $freshPayment = $payment->fresh('billingPlan');
        $freshAccess = $this->buildAccessPayload($freshUser, $freshPayment);

        return $this->ok([
            'success' => true,
            'access_status' => $freshAccess['status'] ?? null,
            'access' => $freshAccess,
            'payment' => $this->serializeAccessPayment($freshPayment),
            'checkout_url' => $session->url,
            'checkoutUrl' => $session->url,
            'checkout_session_id' => $session->id,
            'checkoutSessionId' => $session->id,
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
            'checkoutSessionId' => ['nullable', 'string', 'max:255'],
            'sessionId' => ['nullable', 'string', 'max:255'],
            'stripe_session_id' => ['nullable', 'string', 'max:255'],
        ]);

        $sessionId = $data['session_id']
            ?? $data['checkout_session_id']
            ?? $data['checkoutSessionId']
            ?? $data['sessionId']
            ?? $data['stripe_session_id']
            ?? null;
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
            'payment' => $this->serializeAccessPayment($payment->fresh('billingPlan')),
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

    public function mobileReturn(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'checkout' => ['nullable', 'string', 'max:32'],
            'session_id' => ['nullable', 'string', 'max:255'],
            'checkout_session_id' => ['nullable', 'string', 'max:255'],
        ]);

        $checkout = strtolower((string) ($data['checkout'] ?? 'success'));
        $sessionId = (string) ($data['session_id'] ?? $data['checkout_session_id'] ?? '');

        $query = http_build_query([
            'checkout' => $checkout === 'cancelled' ? 'cancel' : $checkout,
            'session_id' => $sessionId,
            'refresh' => 'commercial_access',
        ]);

        return redirect()->away('redsky://cliente/pago?'.$query);
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

    private function assertAllowedReturnUrl(?string $url): void
    {
        if (! $url) {
            return;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (in_array($scheme, ['http', 'https'], true)) {
            abort_unless(filter_var($url, FILTER_VALIDATE_URL), 422, 'La URL de retorno no es valida.');
            return;
        }

        abort_unless(
            $scheme === 'redsky' && $host === 'cliente',
            422,
            'La URL de retorno movil no esta permitida.'
        );
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
            'success' => true,
            'management_url' => $session->url,
            'managementUrl' => $session->url,
            'portal_session_id' => $session->id,
            'portalSessionId' => $session->id,
            'message' => 'El cliente ya tiene una suscripcion creada. Se genero una sesion del portal de facturacion para administrar su metodo de pago.',
        ];
    }

    private function isStripeHostedUrl(string $url): bool
    {
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' && str_ends_with($host, 'stripe.com');
    }

    private function buildCheckoutLineItem($plan, float $amount): array
    {
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

    private function createPendingPayment(
        int $userId,
        int $planId,
        float $amount,
        string $currency,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $pricing,
    ): AccessPayment {
        return AccessPayment::create([
            'user_id' => $userId,
            'billing_plan_id' => $planId,
            'amount' => $amount,
            'currency' => $currency,
            'billing_period_start' => $periodStart->toDateString(),
            'billing_period_end' => $periodEnd->toDateString(),
            'status' => 'pending',
            'provider' => 'stripe',
            'gateway_response' => [
                'pricing' => $pricing,
            ],
        ]);
    }

    private function resolveMonthlyAccessPeriod(): array
    {
        $start = now();
        $end = $start->copy()->addMonthNoOverflow();

        return [$start, $end];
    }

    private function buildAccessPayload(
        Usuario $user,
        ?AccessPayment $latestPayment = null,
        ?array $paymentPreview = null,
    ): array
    {
        $access = $user->accessStatus()['commercial_access'];

        return array_merge($access, [
            'latest_payment' => $this->serializeAccessPayment($latestPayment ?? $this->latestPaymentForUser($user)),
            'payment_preview' => $paymentPreview ?? $this->resolveAccessPaymentPreview(),
        ]);
    }

    private function resolveAccessPaymentPreview(): ?array
    {
        $plan = $this->billingPlanServicio->findActiveByCode(BillingPlanServicio::CLIENT_ACCESS_CODE);
        if (! $plan) {
            return null;
        }

        $baseAmount = (float) ($plan->amount ?: $plan->price ?: 0);
        if ($baseAmount <= 0) {
            return null;
        }

        $pricing = $this->paymentFeeCalculationServicio->membershipBreakdown($baseAmount);

        return [
            ...$pricing,
            'currency' => strtoupper((string) ($plan->currency ?: 'USD')),
            'billing_plan' => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'amount' => $plan->amount,
                'currency' => $plan->currency,
            ],
        ];
    }

    private function latestPaymentForUser(Usuario $user): ?AccessPayment
    {
        return AccessPayment::query()
            ->with('billingPlan:id,code,name,amount,currency')
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    private function paymentCanBeSyncedFromCheckout(AccessPayment $payment): bool
    {
        $sessionId = trim((string) $payment->provider_checkout_id);
        if ($sessionId === '' || ! config('services.stripe.secret')) {
            return false;
        }

        return in_array((string) $payment->status, [
            'pending',
            'processing',
            'requires_payment_method',
            'requires_confirmation',
            'requires_action',
            'payment_pending',
        ], true);
    }

    private function serializeAccessPayment(?AccessPayment $payment): ?array
    {
        if (! $payment) {
            return null;
        }

        $gatewayResponse = is_array($payment->gateway_response) ? $payment->gateway_response : [];
        $storedPricing = is_array($gatewayResponse['pricing'] ?? null) ? $gatewayResponse['pricing'] : [];
        $baseAmount = (float) ($storedPricing['base_amount'] ?? ($payment->billingPlan?->amount ?? $payment->billingPlan?->price ?? $payment->amount ?? 0));
        $stripeFee = (float) ($storedPricing['stripe_fee'] ?? max(round(((float) $payment->amount) - $baseAmount, 2), 0));
        $administrativeFee = (float) ($storedPricing['administrative_fee'] ?? 0);
        $totalAmount = (float) ($storedPricing['total_amount'] ?? ($payment->amount ?? ($baseAmount + $stripeFee + $administrativeFee)));

        return [
            'id' => $payment->id,
            'user_id' => $payment->user_id,
            'billing_plan_id' => $payment->billing_plan_id,
            'amount' => round((float) $payment->amount, 2),
            'base_amount' => round($baseAmount, 2),
            'stripe_fee' => round($stripeFee, 2),
            'administrative_fee' => round($administrativeFee, 2),
            'total_amount' => round($totalAmount, 2),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'provider' => $payment->provider,
            'provider_payment_id' => $payment->provider_payment_id,
            'provider_invoice_id' => $payment->provider_invoice_id,
            'provider_subscription_id' => $payment->provider_subscription_id,
            'provider_customer_id' => $payment->provider_customer_id,
            'provider_checkout_id' => $payment->provider_checkout_id,
            'card_brand' => $payment->card_brand,
            'card_last4' => $payment->card_last4,
            'billing_period_start' => $payment->billing_period_start,
            'billing_period_end' => $payment->billing_period_end,
            'paid_at' => $payment->paid_at,
            'failure_reason' => $payment->failure_reason,
            'retry_count' => $payment->retry_count,
            'grace_period_ends_at' => $payment->grace_period_ends_at,
            'billing_plan' => $payment->billingPlan ? [
                'id' => $payment->billingPlan->id,
                'code' => $payment->billingPlan->code,
                'name' => $payment->billingPlan->name,
                'amount' => $payment->billingPlan->amount,
                'currency' => $payment->billingPlan->currency,
            ] : null,
            'gateway_response' => $gatewayResponse,
            'created_at' => $payment->created_at,
            'updated_at' => $payment->updated_at,
        ];
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

        $subscriptionStatus = (string) ($subscription->status ?? '');
        $sessionStatus = (string) ($session->status ?? '');
        $sessionPaymentStatus = (string) ($session->payment_status ?? '');
        $invoiceStatus = (string) ($invoicePayload?->status ?? '');
        $invoicePaid = (bool) ($invoicePayload?->paid ?? false);
        $amountPaid = (int) ($invoicePayload?->amount_paid ?? 0);
        $isPaid = in_array($subscriptionStatus, ['active', 'trialing'], true)
            || $sessionPaymentStatus === 'paid'
            || $invoiceStatus === 'paid'
            || $invoicePaid
            || $amountPaid > 0;
        $accessStatus = $this->resolveCheckoutAccessStatus(
            sessionStatus: strtolower(trim($sessionStatus)),
            sessionPaymentStatus: strtolower(trim($sessionPaymentStatus)),
            subscriptionStatus: strtolower(trim($subscriptionStatus)),
            invoiceStatus: strtolower(trim($invoiceStatus)),
            isPaid: $isPaid,
        );
        $paymentStatus = $this->resolveCheckoutPaymentStatus(
            sessionStatus: strtolower(trim($sessionStatus)),
            sessionPaymentStatus: strtolower(trim($sessionPaymentStatus)),
            subscriptionStatus: strtolower(trim($subscriptionStatus)),
            invoiceStatus: strtolower(trim($invoiceStatus)),
            isPaid: $isPaid,
        );
        $periodStart = ! empty($subscription?->current_period_start)
            ? Carbon::createFromTimestamp((int) $subscription->current_period_start)->startOfDay()
            : ($payment->billing_period_start ? Carbon::parse($payment->billing_period_start)->startOfDay() : now()->startOfDay());
        $periodEnd = ! empty($subscription?->current_period_end)
            ? Carbon::createFromTimestamp((int) $subscription->current_period_end)->endOfDay()
            : ($payment->billing_period_end ? Carbon::parse($payment->billing_period_end)->endOfDay() : now()->addMonthNoOverflow()->endOfDay());

        $payload = [
            'pricing' => data_get($payment->gateway_response, 'pricing', []),
            'checkout_session' => json_decode(json_encode($session), true),
            'subscription' => $subscription ? json_decode(json_encode($subscription), true) : null,
            'invoice' => $invoicePayload ? json_decode(json_encode($invoicePayload), true) : null,
        ];

        DB::transaction(function () use ($payment, $session, $subscription, $invoicePayload, $isPaid, $paymentStatus, $accessStatus, $periodStart, $periodEnd, $payload) {
            $customerId = (string) ($session->customer ?? $subscription?->customer ?? $payment->provider_customer_id ?? '');
            $subscriptionId = (string) ($subscription?->id ?? $session->subscription ?? $payment->provider_subscription_id ?? '');
            $invoiceId = (string) ($invoicePayload?->id ?? $session->invoice ?? $payment->provider_invoice_id ?? '');
            $paymentIntentId = (string) ($invoicePayload?->payment_intent ?? $session->payment_intent ?? $payment->provider_payment_id ?? '');

            $payment->update([
                'status' => $paymentStatus,
                'provider_checkout_id' => (string) $session->id,
                'provider_customer_id' => $customerId !== '' ? $customerId : $payment->provider_customer_id,
                'provider_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $payment->provider_subscription_id,
                'provider_invoice_id' => $invoiceId !== '' ? $invoiceId : $payment->provider_invoice_id,
                'provider_payment_id' => $paymentIntentId !== '' ? $paymentIntentId : $payment->provider_payment_id,
                'billing_period_start' => $periodStart->toDateString(),
                'billing_period_end' => $periodEnd->toDateString(),
                'paid_at' => $isPaid ? ($payment->paid_at ?: now()) : $payment->paid_at,
                'gateway_response' => $payload,
            ]);

            if ($isPaid) {
                DB::table('users')
                    ->where('id', $payment->user_id)
                    ->where(function ($query) use ($payment) {
                        $query->whereNull('access_payment_id')
                            ->orWhere('access_payment_id', '<=', $payment->id);
                    })
                    ->update([
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
            } else {
                DB::table('users')
                    ->where('id', $payment->user_id)
                    ->where(function ($query) use ($payment) {
                        $query->whereNull('access_payment_id')
                            ->orWhere('access_payment_id', '<=', $payment->id);
                    })
                    ->update([
                        'access_status' => $accessStatus,
                        'access_payment_id' => $payment->id,
                        'updated_at' => now(),
                    ]);
            }
        });

        return $payment->fresh('billingPlan');
    }

    private function checkoutCanBeReused(array $accessPayload): bool
    {
        $status = strtolower(trim((string) ($accessPayload['status'] ?? '')));

        return in_array($status, ['checkout_pending', 'payment_processing'], true);
    }

    private function resolveCheckoutAccessStatus(
        string $sessionStatus,
        string $sessionPaymentStatus,
        string $subscriptionStatus,
        string $invoiceStatus,
        bool $isPaid,
    ): string {
        if ($isPaid) {
            return 'active';
        }

        if ($sessionStatus === 'expired' || $subscriptionStatus === 'incomplete_expired') {
            return 'expired';
        }

        if (in_array($subscriptionStatus, ['canceled', 'cancelled'], true)) {
            return 'cancelled';
        }

        if (in_array($subscriptionStatus, ['past_due', 'unpaid'], true) || in_array($invoiceStatus, ['uncollectible', 'void'], true)) {
            return 'payment_failed';
        }

        if ($sessionStatus === 'open' || in_array($sessionPaymentStatus, ['unpaid', 'no_payment_required'], true)) {
            return 'checkout_pending';
        }

        return 'payment_processing';
    }

    private function resolveCheckoutPaymentStatus(
        string $sessionStatus,
        string $sessionPaymentStatus,
        string $subscriptionStatus,
        string $invoiceStatus,
        bool $isPaid,
    ): string {
        if ($isPaid) {
            return 'paid';
        }

        if ($sessionStatus === 'expired' || $subscriptionStatus === 'incomplete_expired') {
            return 'expired';
        }

        if (in_array($subscriptionStatus, ['canceled', 'cancelled'], true)) {
            return 'cancelled';
        }

        if (in_array($subscriptionStatus, ['past_due', 'unpaid'], true) || in_array($invoiceStatus, ['uncollectible', 'void'], true)) {
            return 'failed';
        }

        if ($sessionStatus === 'open' || in_array($sessionPaymentStatus, ['unpaid', 'no_payment_required'], true)) {
            return 'pending';
        }

        return 'processing';
    }

    private function resolveCancelledStatus(Usuario $user): string
    {
        if ($user->has_paid_access && in_array((string) $user->access_status, ['active', 'past_due', 'suspended', 'unpaid'], true)) {
            return (string) $user->access_status;
        }

        if (in_array((string) $user->access_status, ['payment_pending', 'checkout_pending', 'payment_processing', 'expired', 'payment_failed'], true)) {
            return 'cancelled';
        }

        $trialEndsAt = $user->trial_ends_at ? Carbon::parse($user->trial_ends_at) : null;
        $trialStillActive = ! $trialEndsAt || $trialEndsAt->isFuture();
        $freeQuoteLimit = max(1, (int) ($user->free_quote_limit ?? 1));
        $freeQuotesUsed = max(0, (int) ($user->free_quotes_used ?? 0));

        return $trialStillActive && $freeQuotesUsed < $freeQuoteLimit ? 'trial_active' : 'trial_used';
    }

    private function hasBlockingActiveAccess(Usuario $user): bool
    {
        $commercialAccess = $user->accessStatus()['commercial_access'] ?? [];
        $isActive = (bool) ($commercialAccess['access_is_active'] ?? false);
        $status = (string) ($commercialAccess['status'] ?? '');
        $expiresAtValue = $commercialAccess['access_expires_at'] ?? $user->access_expires_at;

        if (! $isActive || $status !== 'active' || ! $expiresAtValue) {
            return false;
        }

        $expiresAt = Carbon::parse($expiresAtValue);
        $renewalWindowStart = now()->copy()->addDays(7)->startOfDay();

        return $expiresAt->greaterThan($renewalWindowStart);
    }

    private function hasAnyActiveAccess(Usuario $user): bool
    {
        $commercialAccess = $user->accessStatus()['commercial_access'] ?? [];
        $isActive = (bool) ($commercialAccess['access_is_active'] ?? false);
        $isInGrace = (bool) ($commercialAccess['access_is_in_grace_period'] ?? false);
        $canReserve = (bool) data_get($commercialAccess, 'available_actions.can_reserve', false);

        return $isActive || ($isInGrace && $canReserve);
    }

    private function requiresBillingPortalManagement(Usuario $user): bool
    {
        $commercialAccess = $user->accessStatus()['commercial_access'] ?? [];

        return (bool) $user->provider_customer_id
            && (bool) data_get($commercialAccess, 'available_actions.should_manage_subscription', false);
    }
}
