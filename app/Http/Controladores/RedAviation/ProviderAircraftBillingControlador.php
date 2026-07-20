<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Aeronave;
use App\Modelos\AircraftBillingPayment;
use App\Modelos\Proveedor;
use App\Modelos\SuscripcionAeronave;
use App\Servicios\Aeronaves\AircraftStateService;
use App\Servicios\Billing\BillingPlanServicio;
use App\Servicios\Billing\ProviderAircraftSubscriptionService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class ProviderAircraftBillingControlador extends ControladorBase
{
    public function __construct(
        private readonly BillingPlanServicio $billingPlanServicio,
        private readonly ProviderAircraftSubscriptionService $providerAircraftSubscriptionService,
        private readonly AircraftStateService $aircraftStateService,
    )
    {
    }

    public function create(Request $request, Aeronave $aircraft)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $providerId = $this->resolvedProviderIdOrAbort($request, 403);
        $provider = $request->user()?->provider ?: $request->user()?->ownedProvider;
        abort_if(! $providerId || (int) $aircraft->provider_id !== (int) $providerId, 403, 'No puedes cobrar esta aeronave.');
        abort_if(! $provider?->isApprovedForOperations(), 403, 'Proveedor no aprobado.');

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
        $provider = $provider ?: Proveedor::query()->find($providerId);
        $companyName = $this->resolveProviderCompanyName($provider);
        $aircraftName = $this->resolveAircraftDisplayName($aircraft);
        $visibleDescription = $this->buildStripeAircraftBillingLabel($aircraftName, $companyName);
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

        $baseMetadata = [
            'billing_context' => 'provider_aircraft_subscription',
            'action' => 'activate_aircraft',
            'billing_type' => 'monthly_aircraft_subscription',
            'user_id' => (string) $request->user()->id,
            'provider_id' => (string) $providerId,
            'provider_aircraft_id' => (string) $aircraft->id,
            'aircraft_id' => (string) $aircraft->id,
            'aircraft_name' => $aircraftName,
            'provider_name' => $companyName,
            'company_name' => $companyName,
            'billing_plan_id' => (string) $plan->id,
            'plan_code' => (string) $plan->code,
        ];

        [$payment, $checkoutUrl, $checkoutSessionId, $reusedCheckout] = DB::transaction(function () use (
            $aircraft,
            $providerId,
            $plan,
            $periodStart,
            $periodEnd,
            $amount,
            $successUrl,
            $cancelUrl,
            $request,
            $baseMetadata,
            $visibleDescription,
            $provider,
        ) {
            $lockedAircraft = Aeronave::query()->lockForUpdate()->findOrFail($aircraft->id);
            abort_if((int) $lockedAircraft->provider_id !== (int) $providerId, 403, 'No puedes cobrar esta aeronave.');

            $activeSubscription = SuscripcionAeronave::query()
                ->where('aircraft_id', $lockedAircraft->id)
                ->whereIn('status', ['active', 'trialing'])
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->lockForUpdate()
                ->latest('id')
                ->first();

            abort_if($activeSubscription, 409, 'La aeronave ya cuenta con una suscripcion activa.');

            $reusablePayment = AircraftBillingPayment::query()
                ->where('provider_id', $providerId)
                ->where('aircraft_id', $lockedAircraft->id)
                ->where('billing_plan_id', $plan->id)
                ->where('provider', 'stripe')
                ->whereDate('billing_period_start', $periodStart)
                ->whereDate('billing_period_end', $periodEnd)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $reusablePayment) {
                try {
                    $reusablePayment = AircraftBillingPayment::query()->create([
                        'provider_id' => $providerId,
                        'aircraft_id' => $lockedAircraft->id,
                        'billing_plan_id' => $plan->id,
                        'amount' => $amount,
                        'currency' => strtoupper((string) ($plan->currency ?: 'USD')),
                        'billing_period_start' => $periodStart,
                        'billing_period_end' => $periodEnd,
                        'status' => 'pending',
                        'provider' => 'stripe',
                    ]);
                } catch (QueryException $exception) {
                    $reusablePayment = AircraftBillingPayment::query()
                        ->where('provider_id', $providerId)
                        ->where('aircraft_id', $lockedAircraft->id)
                        ->where('billing_plan_id', $plan->id)
                        ->where('provider', 'stripe')
                        ->whereDate('billing_period_start', $periodStart)
                        ->whereDate('billing_period_end', $periodEnd)
                        ->lockForUpdate()
                        ->latest('id')
                        ->firstOrFail();
                }
            }

            $productDescription = $this->buildStripeAircraftBillingProductDescription(
                $lockedAircraft,
                $provider,
                $plan,
                $reusablePayment->created_at ?: now(),
            );
            $existingCheckoutUrl = (string) data_get($reusablePayment->gateway_response, 'checkout_url', '');
            $existingProductDescription = (string) data_get($reusablePayment->gateway_response, 'product_description', '');
            if (
                $existingCheckoutUrl !== ''
                && filled($reusablePayment->provider_checkout_id)
                && in_array((string) $reusablePayment->status, ['pending', 'open', 'requires_action'], true)
                && $existingProductDescription === $productDescription
            ) {
                return [$reusablePayment->fresh('billingPlan'), $existingCheckoutUrl, (string) $reusablePayment->provider_checkout_id, true];
            }

            $metadata = [
                ...$baseMetadata,
                'aircraft_billing_payment_id' => (string) $reusablePayment->id,
            ];
            $checkoutFingerprint = hash('sha256', json_encode([
                'payment_id' => (int) $reusablePayment->id,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => (string) $request->user()->email,
                'visible_description' => $visibleDescription,
                'product_description' => $productDescription,
                'amount' => (int) round($amount * 100),
                'currency' => strtolower((string) ($plan->currency ?: 'USD')),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'metadata' => $metadata,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $idempotencyKey = sprintf(
                'provider-aircraft-billing:%d:%s',
                $reusablePayment->id,
                substr($checkoutFingerprint, 0, 16),
            );

            $session = Session::create([
                'mode' => 'subscription',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $request->user()->email,
                'client_reference_id' => (string) $reusablePayment->id,
                'metadata' => $metadata,
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower((string) ($plan->currency ?: 'USD')),
                        'product_data' => [
                            'name' => $visibleDescription,
                            'description' => $productDescription,
                        ],
                        'unit_amount' => (int) round($amount * 100),
                        'recurring' => [
                            'interval' => 'month',
                        ],
                    ],
                    'quantity' => 1,
                ]],
                'subscription_data' => [
                    'description' => $visibleDescription,
                    'metadata' => $metadata,
                ],
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);

            $reusablePayment->update([
                'status' => 'pending',
                'provider_checkout_id' => $session->id,
                'gateway_response' => [
                    ...(is_array($reusablePayment->gateway_response) ? $reusablePayment->gateway_response : []),
                    'checkout_url' => $session->url,
                    'description' => $visibleDescription,
                    'product_description' => $productDescription,
                    'checkout_fingerprint' => $checkoutFingerprint,
                    'idempotency_key' => $idempotencyKey,
                ],
            ]);

            $lockedAircraft->forceFill([
                'billing_status' => 'pending_payment',
                'billing_plan_id' => $plan->id,
                'subscription_status' => 'pending',
            ])->save();

            return [$reusablePayment->fresh('billingPlan'), (string) $session->url, (string) $session->id, false];
        });

        $state = $this->aircraftStateService->evaluateAndSyncAircraftState((int) $aircraft->id);
        $refreshedAircraft = Aeronave::query()
            ->with(['provider', 'documents', 'images', 'availability'])
            ->findOrFail($aircraft->id);

        return $this->ok([
            'payment' => $payment,
            'checkout_url' => $checkoutUrl,
            'checkout_session_id' => $checkoutSessionId,
            'reused_checkout' => $reusedCheckout,
            'aircraft' => $this->buildAircraftBillingPayload($refreshedAircraft, $state, $payment, null),
            'state' => $state,
            'billing_state' => $state['billing'] ?? [],
            'activation_state' => $state['activation'] ?? [],
        ], $reusedCheckout ? 200 : 201);
    }

    public function status(Request $request, Aeronave $aircraft)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request, 403);
        abort_if((int) $aircraft->provider_id !== (int) $providerId, 403, 'No puedes consultar esta aeronave.');
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

        $activationState = $this->providerAircraftSubscriptionService->getAircraftActivationEvaluation(
            $aircraft,
            (string) $aircraft->subscription_status,
            $aircraft->subscription_ends_at,
        );
        $billingState = $this->providerAircraftSubscriptionService->buildAircraftBillingSnapshot(
            $aircraft->loadMissing(['provider', 'documents']),
            $latestPayment,
            $subscription,
        );
        $state = $this->aircraftStateService->evaluateAndSyncAircraftState((int) $aircraft->id, $billingState);
        $refreshedAircraft = Aeronave::query()
            ->with(['provider', 'documents', 'images', 'availability'])
            ->findOrFail($aircraft->id);

        return $this->ok([
            'aircraft' => $this->buildAircraftBillingPayload($refreshedAircraft, $state, $latestPayment, $subscription),
            'billing_state' => $state['billing'] ?? $billingState,
            'activation_state' => $state['activation'] ?? $activationState,
            'latest_payment' => $latestPayment,
            'state' => $state,
            'subscription' => $subscription,
        ]);
    }

    private function buildAircraftBillingPayload(
        Aeronave $aircraft,
        array $state,
        ?AircraftBillingPayment $latestPayment = null,
        ?SuscripcionAeronave $subscription = null,
    ): array {
        $billingState = $state['billing'] ?? [];
        $reviewState = $state['review'] ?? [];

        return [
            'id' => $aircraft->id,
            'registration' => $aircraft->registration,
            'model' => $aircraft->model,
            'status' => $aircraft->status,
            'approved_at' => $aircraft->approved_at,
            'approved' => (bool) ($reviewState['approved'] ?? false),
            'review_status' => $reviewState['status'] ?? $aircraft->resolvedReviewStatus(),
            'billing_status' => $billingState['billing_status'] ?? $aircraft->billing_status,
            'billing_plan_id' => $aircraft->billing_plan_id,
            'subscription_status' => $billingState['subscription_status'] ?? $aircraft->subscription_status,
            'subscription_started_at' => $aircraft->subscription_started_at,
            'subscription_ends_at' => $billingState['subscription_ends_at'] ?? $aircraft->subscription_ends_at,
            'last_payment_at' => $billingState['last_payment_at'] ?? $aircraft->last_payment_at,
            'payment_reference' => $subscription?->payment_reference ?: $latestPayment?->provider_subscription_id ?: $latestPayment?->provider_payment_id,
            'provider_checkout_id' => $billingState['checkout_session_id'] ?? $latestPayment?->provider_checkout_id,
            'provider_customer_id' => $latestPayment?->provider_customer_id,
            'provider_subscription_id' => $billingState['stripe_subscription_id'] ?? $latestPayment?->provider_subscription_id ?: $subscription?->provider_subscription_id,
            'provider_invoice_id' => $latestPayment?->provider_invoice_id ?: $subscription?->provider_invoice_id,
            'documents_state' => $state['documents'] ?? [],
            'operation' => $state['operation'] ?? [],
            'pricing' => $state['pricing'] ?? [],
            'matching' => $state['matching'] ?? [],
            'activation' => $state['activation'] ?? [],
            'aircraft_state' => $state,
            'ready_to_quote' => $state['ready_to_quote'] ?? false,
            'ready_to_book' => $state['ready_to_book'] ?? false,
        ];
    }

    public function payments(Request $request)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);

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
                'subscription.latest_invoice',
                'payment_intent',
            ],
        ]);

        $metadata = $this->extractStripeMetadata($session);
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

        $customerId = (string) ($session->customer ?? '');
        $subscriptionId = (string) ($session->subscription->id ?? $session->subscription ?? '');
        $invoiceId = (string) ($session->subscription->latest_invoice->id ?? $session->invoice ?? '');
        $paymentStatus = strtolower((string) ($session->payment_status ?? ''));
        $sessionStatus = strtolower((string) ($session->status ?? ''));
        $subscriptionStatus = strtolower((string) ($session->subscription->status ?? ''));
        $paymentIntentStatus = strtolower((string) ($session->payment_intent->status ?? $session->subscription->latest_invoice->payment_intent->status ?? ''));
        $invoiceStatus = strtolower((string) ($session->subscription->latest_invoice->status ?? ''));
        $isConfirmedPayment =
            in_array($paymentStatus, ['paid', 'no_payment_required'], true)
            || $paymentIntentStatus === 'succeeded'
            || $invoiceStatus === 'paid';

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

        $gatewayPayload = json_decode(json_encode($session), true);
        $amount = ((int) ($session->amount_total ?? 0)) / 100;

        if (! $isConfirmedPayment) {
            $this->providerAircraftSubscriptionService->syncCheckoutState(
                payment: $payment,
                gatewayPayload: $gatewayPayload,
                providerCheckoutId: $sessionId,
                providerSubscriptionId: $subscriptionId,
                providerCustomerId: $customerId,
                providerInvoiceId: $invoiceId,
                providerPaymentId: $providerPaymentId,
                subscriptionStatus: $subscriptionStatus !== '' ? $subscriptionStatus : ($sessionStatus ?: $paymentStatus),
                userId: $userId,
            );

            return;
        }

        $this->providerAircraftSubscriptionService->syncPaidSubscription(
            payment: $payment,
            providerPaymentId: $providerPaymentId,
            providerCustomerId: $customerId,
            providerInvoiceId: $invoiceId,
            checkoutSessionId: $sessionId,
            subscriptionId: $subscriptionId,
            amount: $amount,
            currency: strtoupper((string) ($session->currency ?? $payment->currency ?? 'USD')),
            gatewayPayload: $gatewayPayload,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            userId: $userId,
        );
    }

    private function resolveProviderCompanyName(?Proveedor $provider): string
    {
        return trim((string) (
            $provider?->commercial_name
            ?: $provider?->company_name
            ?: $provider?->legal_name
            ?: $provider?->user?->name
            ?: 'Proveedor'
        ));
    }

    private function resolveAircraftDisplayName(Aeronave $aircraft): string
    {
        return trim((string) ($aircraft->model ?: $aircraft->registration ?: ('Aeronave '.$aircraft->id)));
    }

    private function buildStripeAircraftBillingLabel(string $aircraftName, string $companyName): string
    {
        return trim(sprintf('Mensualidad %s - %s', $aircraftName, $companyName));
    }

    private function buildStripeAircraftBillingProductDescription(
        Aeronave $aircraft,
        ?Proveedor $provider,
        object $plan,
        ?CarbonInterface $generatedAt = null,
    ): string
    {
        $generatedAt = ($generatedAt ?: now())->timezone(config('app.timezone', 'America/Mexico_City'));
        $parts = [
            'Suscripcion mensual para activar comercialmente la aeronave.',
            'Aeronave: '.$this->resolveAircraftDisplayName($aircraft),
            'Proveedor: '.$this->resolveProviderCompanyName($provider),
            'Fecha: '.$generatedAt->format('d/m/Y'),
            'Hora: '.$generatedAt->format('h:i A'),
        ];

        if (! empty($aircraft->registration)) {
            $parts[] = 'Matricula: '.$aircraft->registration;
        }

        if (! empty($plan->description)) {
            $parts[] = trim((string) $plan->description);
        }

        return implode(' ', $parts);
    }

    private function extractStripeMetadata(object $payload): array
    {
        $candidates = [
            json_decode(json_encode($payload->metadata ?? []), true) ?: [],
            json_decode(json_encode($payload->subscription->metadata ?? []), true) ?: [],
            json_decode(json_encode($payload->subscription_details->metadata ?? []), true) ?: [],
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }
}
