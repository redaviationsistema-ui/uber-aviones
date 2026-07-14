<?php

namespace App\Servicios\Billing;

use App\Modelos\Cotizacion;
use App\Modelos\FlightMembership;
use App\Modelos\FlightMembershipBenefitLedger;
use App\Modelos\FlightMembershipPeriod;
use App\Modelos\FlightMembershipPlan;
use App\Modelos\Reserva;
use App\Modelos\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class FlightMembershipService
{
    public const CONTEXT = 'flight_membership_subscription';

    public function activePlansQuery()
    {
        return FlightMembershipPlan::query()->where('is_active', true);
    }

    public function findCurrentMembershipForUser(int $userId): ?FlightMembership
    {
        return FlightMembership::query()
            ->with(['plan', 'currentPeriod'])
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest('id')
            ->first();
    }

    public function findActiveMembershipForUser(int $userId): ?FlightMembership
    {
        return FlightMembership::query()
            ->with(['plan', 'currentPeriod'])
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now());
            })
            ->latest('id')
            ->first();
    }

    public function createCheckout(Usuario $user, FlightMembershipPlan $plan, array $payload): array
    {
        $activeMembership = $this->findActiveMembershipForUser((int) $user->id);
        if ($activeMembership) {
            return [
                'already_active' => true,
                'membership' => $this->serializeMembership($activeMembership->fresh(['plan', 'currentPeriod'])),
            ];
        }

        $membership = FlightMembership::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->where('status', 'pending_payment')
            ->whereNull('stripe_subscription_id')
            ->latest('id')
            ->first();

        if (! $membership) {
            $membership = FlightMembership::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 'pending_payment',
            ]);
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $successUrl = $payload['success_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/membresia-vuelo?checkout=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $payload['cancel_url']
            ?? rtrim((string) config('services.stripe.frontend_url'), '/').'/cliente/membresia-vuelo?checkout=cancelled&session_id={CHECKOUT_SESSION_ID}';

        $metadata = [
            'context' => self::CONTEXT,
            'billing_context' => self::CONTEXT,
            'user_id' => (string) $user->id,
            'plan_id' => (string) $plan->id,
            'membership_id' => (string) $membership->id,
            'plan_name' => (string) $plan->name,
        ];

        $lineItem = $plan->stripe_price_id
            ? ['price' => $plan->stripe_price_id, 'quantity' => 1]
            : [
                'price_data' => [
                    'currency' => strtolower((string) ($plan->currency ?: 'USD')),
                    'product_data' => [
                        'name' => sprintf('Membresia de vuelo %s - %s', $plan->name, $user->name ?: $user->email),
                        'description' => (string) ($plan->description ?: 'Membresia recurrente de beneficios de vuelo.'),
                    ],
                    'unit_amount' => (int) round((float) $plan->price * 100),
                    'recurring' => [
                        'interval' => $plan->billing_interval === 'yearly' ? 'year' : 'month',
                    ],
                ],
                'quantity' => 1,
            ];

        $sessionPayload = [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $membership->id,
            'metadata' => $metadata,
            'line_items' => [$lineItem],
            'subscription_data' => [
                'description' => sprintf('Membresia de vuelo %s - %s', $plan->name, $user->name ?: $user->email),
                'metadata' => $metadata,
            ],
        ];

        if ($user->provider_customer_id) {
            $sessionPayload['customer'] = (string) $user->provider_customer_id;
        } else {
            $sessionPayload['customer_email'] = $payload['contact_email'] ?? $user->email;
        }

        $session = Session::create($sessionPayload);

        $membership->update([
            'stripe_checkout_session_id' => (string) $session->id,
        ]);

        return [
            'membership' => $this->serializeMembership($membership->fresh(['plan', 'currentPeriod'])),
            'checkout_url' => $session->url,
            'checkout_session_id' => $session->id,
        ];
    }

    public function syncCheckoutCompleted(object $session, array $metadata): void
    {
        $membership = $this->findMembershipByStripeContext(
            membershipId: (int) ($metadata['membership_id'] ?? 0),
            subscriptionId: (string) ($session->subscription ?? ''),
            checkoutSessionId: (string) ($session->id ?? ''),
        );

        if (! $membership) {
            return;
        }

        $membership->update([
            'stripe_customer_id' => (string) ($session->customer ?? $membership->stripe_customer_id),
            'stripe_subscription_id' => (string) ($session->subscription ?? $membership->stripe_subscription_id),
            'stripe_checkout_session_id' => (string) ($session->id ?? $membership->stripe_checkout_session_id),
            'status' => 'pending_payment',
        ]);
    }

    public function handleInvoicePaid(object $invoice, array $metadata): void
    {
        $membership = $this->findMembershipByStripeContext(
            membershipId: (int) ($metadata['membership_id'] ?? 0),
            subscriptionId: (string) ($invoice->subscription ?? ''),
            checkoutSessionId: (string) ($metadata['stripe_checkout_session_id'] ?? $metadata['checkout_session_id'] ?? ''),
        );

        if (! $membership) {
            $userId = (int) ($metadata['user_id'] ?? 0);
            $planId = (int) ($metadata['plan_id'] ?? 0);
            if ($userId <= 0 || $planId <= 0) {
                return;
            }

            $membership = FlightMembership::create([
                'user_id' => $userId,
                'plan_id' => $planId,
                'status' => 'pending_payment',
                'stripe_subscription_id' => (string) ($invoice->subscription ?? null),
            ]);
        }

        DB::transaction(function () use ($membership, $invoice) {
            $membership = FlightMembership::query()
                ->with('plan')
                ->lockForUpdate()
                ->findOrFail($membership->id);

            $periodStart = $this->extractInvoicePeriod($invoice, 'start') ?: now()->startOfMonth();
            $periodEnd = $this->extractInvoicePeriod($invoice, 'end') ?: $this->resolvePeriodEndFromPlan($membership->plan, $periodStart);
            $invoiceId = (string) ($invoice->id ?? '');
            $periodKey = $this->buildPeriodKey($membership->id, $periodStart, $periodEnd);

            $membership->update([
                'status' => 'active',
                'starts_at' => $membership->starts_at ?: $periodStart,
                'ends_at' => $periodEnd,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'stripe_customer_id' => (string) ($invoice->customer ?? $membership->stripe_customer_id),
                'stripe_subscription_id' => (string) ($invoice->subscription ?? $membership->stripe_subscription_id),
                'last_invoice_id' => $invoiceId !== '' ? $invoiceId : $membership->last_invoice_id,
                'last_payment_at' => now(),
                'canceled_at' => null,
            ]);

            $period = FlightMembershipPeriod::query()
                ->firstOrCreate(
                    [
                        'flight_membership_id' => $membership->id,
                        'membership_period_key' => $periodKey,
                    ],
                    [
                        'stripe_invoice_id' => $invoiceId !== '' ? $invoiceId : null,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'status' => 'active',
                    ],
                );

            if ($period->stripe_invoice_id === null && $invoiceId !== '') {
                $period->update(['stripe_invoice_id' => $invoiceId]);
            }

            $existingGrantCount = $period->ledgerEntries()
                ->where('entry_type', 'grant')
                ->count();

            if ($existingGrantCount === 0) {
                $rollover = $this->closePreviousPeriodAndResolveRollover($membership, $period);
                $this->grantBenefitsForPeriod($membership, $period, $rollover, $invoiceId);
            }

            $this->syncMaterializedBalances($period);
        });
    }

    public function handleInvoicePaymentFailed(object $invoice, array $metadata): void
    {
        $membership = $this->findMembershipByStripeContext(
            membershipId: (int) ($metadata['membership_id'] ?? 0),
            subscriptionId: (string) ($invoice->subscription ?? ''),
            checkoutSessionId: '',
        );

        if (! $membership) {
            return;
        }

        $membership->update([
            'status' => 'past_due',
            'stripe_customer_id' => (string) ($invoice->customer ?? $membership->stripe_customer_id),
            'stripe_subscription_id' => (string) ($invoice->subscription ?? $membership->stripe_subscription_id),
            'last_invoice_id' => (string) ($invoice->id ?? $membership->last_invoice_id),
        ]);
    }

    public function handleSubscriptionUpdated(object $payload, array $metadata): void
    {
        $membership = $this->findMembershipByStripeContext(
            membershipId: (int) ($metadata['membership_id'] ?? 0),
            subscriptionId: (string) ($payload->id ?? ''),
            checkoutSessionId: '',
        );

        if (! $membership) {
            return;
        }

        $status = (string) ($payload->status ?? 'active');
        $mappedStatus = match ($status) {
            'active' => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'canceled' => 'canceled',
            default => $membership->status,
        };

        $membership->update([
            'status' => $mappedStatus,
            'stripe_customer_id' => (string) ($payload->customer ?? $membership->stripe_customer_id),
            'stripe_subscription_id' => (string) ($payload->id ?? $membership->stripe_subscription_id),
            'current_period_start' => ! empty($payload->current_period_start)
                ? Carbon::createFromTimestamp((int) $payload->current_period_start)
                : $membership->current_period_start,
            'current_period_end' => ! empty($payload->current_period_end)
                ? Carbon::createFromTimestamp((int) $payload->current_period_end)
                : $membership->current_period_end,
            'cancel_at_period_end' => (bool) ($payload->cancel_at_period_end ?? $membership->cancel_at_period_end),
            'canceled_at' => ! empty($payload->canceled_at)
                ? Carbon::createFromTimestamp((int) $payload->canceled_at)
                : $membership->canceled_at,
        ]);
    }

    public function handleSubscriptionDeleted(object $payload, array $metadata): void
    {
        $membership = $this->findMembershipByStripeContext(
            membershipId: (int) ($metadata['membership_id'] ?? 0),
            subscriptionId: (string) ($payload->id ?? ''),
            checkoutSessionId: '',
        );

        if (! $membership) {
            return;
        }

        $membership->update([
            'status' => 'canceled',
            'stripe_customer_id' => (string) ($payload->customer ?? $membership->stripe_customer_id),
            'stripe_subscription_id' => (string) ($payload->id ?? $membership->stripe_subscription_id),
            'canceled_at' => ! empty($payload->canceled_at)
                ? Carbon::createFromTimestamp((int) $payload->canceled_at)
                : now(),
            'ends_at' => ! empty($payload->ended_at)
                ? Carbon::createFromTimestamp((int) $payload->ended_at)
                : $membership->ends_at,
        ]);
    }

    public function previewForQuote(?Usuario $user, Cotizacion $quote): array
    {
        if (! $user) {
            return ['membership_available' => false];
        }

        $membership = $this->findActiveMembershipForUser((int) $user->id);
        if (! $membership) {
            return ['membership_available' => false];
        }

        $period = $this->resolveCurrentPeriod($membership);
        if (! $period) {
            return ['membership_available' => false];
        }

        $balances = $this->balanceSnapshot($period);
        $pricingContext = is_array($quote->flightRequest?->pricing_context) ? $quote->flightRequest->pricing_context : [];
        $billableHours = (float) (
            $pricingContext['billable_hours']
            ?? $pricingContext['final_hours']
            ?? $pricingContext['card_flight_hours']
            ?? $pricingContext['client_display_flight_hours']
            ?? 0
        );
        $hourlyRate = (float) (
            $pricingContext['hourly_rate']
            ?? $quote->aircraft?->hourly_rate
            ?? 0
        );
        $flightCost = (float) (
            $pricingContext['flight_cost']
            ?? $pricingContext['client_flight_cost']
            ?? $quote->total
            ?? 0
        );
        $total = (float) ($quote->total ?? 0);
        $remainingTotal = $total;

        $flightsToUse = 0.0;
        $hoursToUse = 0.0;
        $creditToUse = 0.0;
        $discountAmount = 0.0;
        $flightAmount = 0.0;

        // Supuesto operativo inicial: si hay vuelo incluido disponible, se aplica primero
        // sobre el costo base del vuelo para evitar duplicar el mismo beneficio con horas.
        if ($balances['flights_available'] >= 1 && $remainingTotal > 0) {
            $flightsToUse = 1.0;
            $flightAmount = round(min($remainingTotal, $flightCost > 0 ? $flightCost : $remainingTotal), 2);
            $remainingTotal = round(max($remainingTotal - $flightAmount, 0), 2);
        } else {
            $hoursToUse = round(min($balances['hours_available'], $billableHours), 2);
            $hourAmount = round(min($remainingTotal, $hoursToUse * max($hourlyRate, 0)), 2);
            $remainingTotal = round(max($remainingTotal - $hourAmount, 0), 2);
        }

        $creditToUse = round(min($balances['credit_available'], $remainingTotal), 2);
        $remainingTotal = round(max($remainingTotal - $creditToUse, 0), 2);

        $discountAmount = round($remainingTotal * (((float) $membership->plan->discount_percentage) / 100), 2);
        $remainingTotal = round(max($remainingTotal - $discountAmount, 0), 2);

        return [
            'membership_available' => true,
            'membership_id' => $membership->id,
            'plan_name' => $membership->plan->name,
            'benefits' => [
                'hours_available' => round($balances['hours_available'], 2),
                'flights_available' => round($balances['flights_available'], 2),
                'credit_available' => round($balances['credit_available'], 2),
                'discount_percentage' => round((float) $membership->plan->discount_percentage, 2),
            ],
            'application_preview' => [
                'flights_to_use' => $flightsToUse,
                'flight_amount' => round($flightAmount, 2),
                'hours_to_use' => $hoursToUse,
                'credit_to_use' => $creditToUse,
                'discount_amount' => $discountAmount,
                'estimated_total' => $remainingTotal,
            ],
        ];
    }

    public function consumeBenefitsForReservation(Reserva $reservation): void
    {
        $reservation->loadMissing(['quote.flightRequest', 'quote.aircraft', 'client']);
        if (! $reservation->quote || ! $reservation->client_id) {
            return;
        }

        DB::transaction(function () use ($reservation) {
            $membership = FlightMembership::query()
                ->with('plan')
                ->where('user_id', $reservation->client_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $membership) {
                return;
            }

            $period = $this->resolveCurrentPeriod($membership, true);
            if (! $period) {
                return;
            }

            $alreadyConsumed = FlightMembershipBenefitLedger::query()
                ->where('flight_membership_id', $membership->id)
                ->where('reservation_id', $reservation->id)
                ->where('entry_type', 'consume')
                ->exists();

            if ($alreadyConsumed) {
                return;
            }

            $preview = $this->previewForQuote($reservation->client, $reservation->quote);
            if (! ($preview['membership_available'] ?? false)) {
                return;
            }

            $application = $preview['application_preview'] ?? [];
            $benefits = $preview['benefits'] ?? [];
            $referenceBase = 'reservation:'.$reservation->id;

            if ((float) ($application['flights_to_use'] ?? 0) > 0) {
                $this->createLedgerEntry($membership, $period, [
                    'quote_id' => $reservation->quote_id,
                    'flight_id' => $reservation->flight_request_id,
                    'reservation_id' => $reservation->id,
                    'entry_type' => 'consume',
                    'benefit_type' => 'flight',
                    'quantity' => -1 * (float) $application['flights_to_use'],
                    'amount' => -1 * (float) ($application['flight_amount'] ?? 0),
                    'reference' => $referenceBase.':flight',
                    'metadata' => [
                        'source' => 'reservation_payment_confirmed',
                        'available_before' => $benefits['flights_available'] ?? null,
                    ],
                ]);
            }

            if ((float) ($application['hours_to_use'] ?? 0) > 0) {
                $pricingContext = is_array($reservation->quote->flightRequest?->pricing_context)
                    ? $reservation->quote->flightRequest->pricing_context
                    : [];
                $hourlyRate = (float) ($pricingContext['hourly_rate'] ?? $reservation->quote->aircraft?->hourly_rate ?? 0);

                $this->createLedgerEntry($membership, $period, [
                    'quote_id' => $reservation->quote_id,
                    'flight_id' => $reservation->flight_request_id,
                    'reservation_id' => $reservation->id,
                    'entry_type' => 'consume',
                    'benefit_type' => 'hour',
                    'quantity' => -1 * (float) $application['hours_to_use'],
                    'amount' => -1 * round(((float) $application['hours_to_use']) * $hourlyRate, 2),
                    'reference' => $referenceBase.':hour',
                    'metadata' => [
                        'source' => 'reservation_payment_confirmed',
                    ],
                ]);
            }

            if ((float) ($application['credit_to_use'] ?? 0) > 0) {
                $this->createLedgerEntry($membership, $period, [
                    'quote_id' => $reservation->quote_id,
                    'flight_id' => $reservation->flight_request_id,
                    'reservation_id' => $reservation->id,
                    'entry_type' => 'consume',
                    'benefit_type' => 'credit',
                    'quantity' => 0,
                    'amount' => -1 * (float) $application['credit_to_use'],
                    'reference' => $referenceBase.':credit',
                    'metadata' => [
                        'source' => 'reservation_payment_confirmed',
                    ],
                ]);
            }

            if ((float) ($application['discount_amount'] ?? 0) > 0) {
                $this->createLedgerEntry($membership, $period, [
                    'quote_id' => $reservation->quote_id,
                    'flight_id' => $reservation->flight_request_id,
                    'reservation_id' => $reservation->id,
                    'entry_type' => 'consume',
                    'benefit_type' => 'discount',
                    'quantity' => 0,
                    'amount' => -1 * (float) $application['discount_amount'],
                    'reference' => $referenceBase.':discount',
                    'metadata' => [
                        'source' => 'reservation_payment_confirmed',
                        'discount_percentage' => $membership->plan->discount_percentage,
                    ],
                ]);
            }

            $this->syncMaterializedBalances($period);
        });
    }

    public function reverseBenefitsForReservation(Reserva $reservation, string $reason = ''): void
    {
        DB::transaction(function () use ($reservation, $reason) {
            $entries = FlightMembershipBenefitLedger::query()
                ->where('reservation_id', $reservation->id)
                ->where('entry_type', 'consume')
                ->where('status', 'posted')
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                $existingReversal = FlightMembershipBenefitLedger::query()
                    ->where('reversed_entry_id', $entry->id)
                    ->exists();

                if ($existingReversal) {
                    continue;
                }

                $this->createLedgerEntry($entry->membership, $entry->period, [
                    'quote_id' => $entry->quote_id,
                    'flight_id' => $entry->flight_id,
                    'reservation_id' => $entry->reservation_id,
                    'entry_type' => 'reversal',
                    'benefit_type' => $entry->benefit_type,
                    'quantity' => -1 * (float) $entry->quantity,
                    'amount' => -1 * (float) $entry->amount,
                    'reference' => (string) ($entry->reference ?: 'reservation:'.$reservation->id).':reversal',
                    'metadata' => [
                        'source' => 'reservation_cancellation',
                        'reason' => $reason,
                    ],
                    'reversed_entry_id' => $entry->id,
                ]);

                $entry->update(['status' => 'reversed']);
                if ($entry->period) {
                    $this->syncMaterializedBalances($entry->period);
                }
            }
        });
    }

    public function createManualAdjustment(FlightMembership $membership, array $data): FlightMembershipBenefitLedger
    {
        return DB::transaction(function () use ($membership, $data) {
            $membership = FlightMembership::query()->with('plan')->lockForUpdate()->findOrFail($membership->id);
            $period = $this->resolveCurrentPeriod($membership, true) ?: $this->createFallbackPeriod($membership);
            $benefitType = (string) $data['benefit_type'];
            $quantity = (float) ($data['quantity'] ?? 0);
            $amount = (float) ($data['amount'] ?? 0);

            $entry = $this->createLedgerEntry($membership, $period, [
                'entry_type' => 'adjustment',
                'benefit_type' => $benefitType,
                'quantity' => in_array($benefitType, ['flight', 'hour'], true) ? $quantity : 0,
                'amount' => in_array($benefitType, ['credit', 'discount'], true) ? $amount : $amount,
                'reference' => 'admin-adjustment:'.Str::uuid(),
                'metadata' => [
                    'reason' => $data['reason'],
                    'actor_user_id' => $data['actor_user_id'] ?? null,
                ],
            ]);

            $this->syncMaterializedBalances($period);

            return $entry->fresh();
        });
    }

    public function serializePlan(FlightMembershipPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'price' => round((float) $plan->price, 2),
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'included_flights' => round((float) $plan->included_flights, 2),
            'included_hours' => round((float) $plan->included_hours, 2),
            'included_credit_amount' => round((float) $plan->included_credit_amount, 2),
            'discount_percentage' => round((float) $plan->discount_percentage, 2),
            'rollover_flights' => (bool) $plan->rollover_flights,
            'rollover_hours' => (bool) $plan->rollover_hours,
            'rollover_credits' => (bool) $plan->rollover_credits,
            'validity_days' => $plan->validity_days,
            'auto_renew' => (bool) $plan->auto_renew,
            'is_active' => (bool) $plan->is_active,
            'stripe_product_id' => $plan->stripe_product_id,
            'stripe_price_id' => $plan->stripe_price_id,
        ];
    }

    public function serializeMembership(FlightMembership $membership): array
    {
        $membership->loadMissing(['plan', 'currentPeriod']);
        $period = $this->resolveCurrentPeriod($membership) ?: $membership->currentPeriod;
        $balances = $period ? $this->balanceSnapshot($period) : [
            'flights_available' => 0,
            'hours_available' => 0,
            'credit_available' => 0,
        ];

        return [
            'id' => $membership->id,
            'status' => $membership->status,
            'starts_at' => $membership->starts_at,
            'ends_at' => $membership->ends_at,
            'current_period_start' => $membership->current_period_start,
            'current_period_end' => $membership->current_period_end,
            'stripe_customer_id' => $membership->stripe_customer_id,
            'stripe_subscription_id' => $membership->stripe_subscription_id,
            'stripe_checkout_session_id' => $membership->stripe_checkout_session_id,
            'last_invoice_id' => $membership->last_invoice_id,
            'last_payment_at' => $membership->last_payment_at,
            'cancel_at_period_end' => (bool) $membership->cancel_at_period_end,
            'canceled_at' => $membership->canceled_at,
            'plan' => $membership->plan ? $this->serializePlan($membership->plan) : null,
            'balances' => [
                'flights_available' => round($balances['flights_available'], 2),
                'hours_available' => round($balances['hours_available'], 2),
                'credit_available' => round($balances['credit_available'], 2),
                'discount_percentage' => round((float) ($membership->plan?->discount_percentage ?? 0), 2),
            ],
        ];
    }

    public function serializeLedgerEntry(FlightMembershipBenefitLedger $entry): array
    {
        return [
            'id' => $entry->id,
            'flight_membership_id' => $entry->flight_membership_id,
            'flight_membership_period_id' => $entry->flight_membership_period_id,
            'membership_period_key' => $entry->membership_period_key,
            'quote_id' => $entry->quote_id,
            'flight_id' => $entry->flight_id,
            'reservation_id' => $entry->reservation_id,
            'entry_type' => $entry->entry_type,
            'benefit_type' => $entry->benefit_type,
            'quantity' => round((float) $entry->quantity, 2),
            'amount' => round((float) $entry->amount, 2),
            'status' => $entry->status,
            'reference' => $entry->reference,
            'metadata' => $entry->metadata,
            'occurred_at' => $entry->occurred_at,
            'reversed_entry_id' => $entry->reversed_entry_id,
        ];
    }

    public function isFlightMembershipContext(array $metadata, string $subscriptionId = '', string $checkoutSessionId = ''): bool
    {
        if (($metadata['billing_context'] ?? null) === self::CONTEXT || ($metadata['context'] ?? null) === self::CONTEXT) {
            return true;
        }

        if ($subscriptionId === '' && $checkoutSessionId === '') {
            return false;
        }

        return FlightMembership::query()
            ->when($subscriptionId !== '', fn ($query) => $query->where('stripe_subscription_id', $subscriptionId))
            ->when($checkoutSessionId !== '', fn ($query) => $query->orWhere('stripe_checkout_session_id', $checkoutSessionId))
            ->exists();
    }

    private function resolveCurrentPeriod(FlightMembership $membership, bool $forUpdate = false): ?FlightMembershipPeriod
    {
        $query = FlightMembershipPeriod::query()
            ->where('flight_membership_id', $membership->id)
            ->where('period_end', '>=', now()->subYear())
            ->latest('period_end');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function createFallbackPeriod(FlightMembership $membership): FlightMembershipPeriod
    {
        $start = $membership->current_period_start ?: now()->startOfMonth();
        $end = $membership->current_period_end ?: $this->resolvePeriodEndFromPlan($membership->plan, Carbon::parse($start));

        return FlightMembershipPeriod::create([
            'flight_membership_id' => $membership->id,
            'membership_period_key' => $this->buildPeriodKey($membership->id, Carbon::parse($start), Carbon::parse($end)),
            'period_start' => $start,
            'period_end' => $end,
            'status' => 'active',
        ]);
    }

    private function findMembershipByStripeContext(int $membershipId, string $subscriptionId, string $checkoutSessionId): ?FlightMembership
    {
        if ($membershipId > 0) {
            $membership = FlightMembership::query()->with('plan')->find($membershipId);
            if ($membership) {
                return $membership;
            }
        }

        if ($subscriptionId !== '') {
            $membership = FlightMembership::query()
                ->with('plan')
                ->where('stripe_subscription_id', $subscriptionId)
                ->latest('id')
                ->first();

            if ($membership) {
                return $membership;
            }
        }

        if ($checkoutSessionId !== '') {
            return FlightMembership::query()
                ->with('plan')
                ->where('stripe_checkout_session_id', $checkoutSessionId)
                ->latest('id')
                ->first();
        }

        return null;
    }

    private function extractInvoicePeriod(object $invoice, string $edge): ?Carbon
    {
        $timestamp = Arr::get(json_decode(json_encode($invoice), true), "lines.data.0.period.$edge");

        return $timestamp ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    private function resolvePeriodEndFromPlan(?FlightMembershipPlan $plan, Carbon $start): Carbon
    {
        if (($plan?->billing_interval ?? 'monthly') === 'yearly') {
            return $start->copy()->addYear()->subSecond();
        }

        return $start->copy()->addMonthNoOverflow()->subSecond();
    }

    private function buildPeriodKey(int $membershipId, Carbon $start, Carbon $end): string
    {
        return sprintf(
            '%d:%s:%s',
            $membershipId,
            $start->format('YmdHis'),
            $end->format('YmdHis')
        );
    }

    private function closePreviousPeriodAndResolveRollover(FlightMembership $membership, FlightMembershipPeriod $newPeriod): array
    {
        $previousPeriod = FlightMembershipPeriod::query()
            ->where('flight_membership_id', $membership->id)
            ->where('id', '!=', $newPeriod->id)
            ->latest('period_end')
            ->lockForUpdate()
            ->first();

        if (! $previousPeriod) {
            return [
                'flights' => 0.0,
                'hours' => 0.0,
                'credit' => 0.0,
            ];
        }

        $balances = $this->balanceSnapshot($previousPeriod);
        $rolloverFlights = (bool) $membership->plan->rollover_flights ? $balances['flights_available'] : 0.0;
        $rolloverHours = (bool) $membership->plan->rollover_hours ? $balances['hours_available'] : 0.0;
        $rolloverCredit = (bool) $membership->plan->rollover_credits ? $balances['credit_available'] : 0.0;

        if (! $membership->plan->rollover_flights && $balances['flights_available'] > 0) {
            $this->createLedgerEntry($membership, $previousPeriod, [
                'entry_type' => 'expiration',
                'benefit_type' => 'flight',
                'quantity' => -1 * $balances['flights_available'],
                'amount' => 0,
                'reference' => 'period-expiration:'.$previousPeriod->id.':flight',
                'metadata' => ['source' => 'period_rollover'],
            ]);
        }

        if (! $membership->plan->rollover_hours && $balances['hours_available'] > 0) {
            $this->createLedgerEntry($membership, $previousPeriod, [
                'entry_type' => 'expiration',
                'benefit_type' => 'hour',
                'quantity' => -1 * $balances['hours_available'],
                'amount' => 0,
                'reference' => 'period-expiration:'.$previousPeriod->id.':hour',
                'metadata' => ['source' => 'period_rollover'],
            ]);
        }

        if (! $membership->plan->rollover_credits && $balances['credit_available'] > 0) {
            $this->createLedgerEntry($membership, $previousPeriod, [
                'entry_type' => 'expiration',
                'benefit_type' => 'credit',
                'quantity' => 0,
                'amount' => -1 * $balances['credit_available'],
                'reference' => 'period-expiration:'.$previousPeriod->id.':credit',
                'metadata' => ['source' => 'period_rollover'],
            ]);
        }

        $previousPeriod->update([
            'status' => ($rolloverFlights > 0 || $rolloverHours > 0 || $rolloverCredit > 0) ? 'rolled_over' : 'expired',
        ]);
        $this->syncMaterializedBalances($previousPeriod);

        return [
            'flights' => round(max($rolloverFlights, 0), 2),
            'hours' => round(max($rolloverHours, 0), 2),
            'credit' => round(max($rolloverCredit, 0), 2),
        ];
    }

    private function grantBenefitsForPeriod(FlightMembership $membership, FlightMembershipPeriod $period, array $rollover, string $invoiceId): void
    {
        $plan = $membership->plan;
        $grants = [
            'flight' => round((float) $plan->included_flights + (float) ($rollover['flights'] ?? 0), 2),
            'hour' => round((float) $plan->included_hours + (float) ($rollover['hours'] ?? 0), 2),
            'credit' => round((float) $plan->included_credit_amount + (float) ($rollover['credit'] ?? 0), 2),
        ];

        foreach ($grants as $benefitType => $value) {
            if ($value <= 0) {
                continue;
            }

            $this->createLedgerEntry($membership, $period, [
                'entry_type' => 'grant',
                'benefit_type' => $benefitType,
                'quantity' => in_array($benefitType, ['flight', 'hour'], true) ? $value : 0,
                'amount' => $benefitType === 'credit' ? $value : 0,
                'reference' => 'invoice:'.$invoiceId.':'.$benefitType,
                'metadata' => [
                    'source' => 'invoice.paid',
                    'rollover_applied' => $rollover,
                ],
            ]);
        }
    }

    private function createLedgerEntry(FlightMembership $membership, ?FlightMembershipPeriod $period, array $attributes): FlightMembershipBenefitLedger
    {
        return FlightMembershipBenefitLedger::create([
            'flight_membership_id' => $membership->id,
            'flight_membership_period_id' => $period?->id,
            'membership_period_key' => $period?->membership_period_key ?: (string) ($attributes['membership_period_key'] ?? 'manual'),
            'quote_id' => $attributes['quote_id'] ?? null,
            'flight_id' => $attributes['flight_id'] ?? null,
            'reservation_id' => $attributes['reservation_id'] ?? null,
            'entry_type' => $attributes['entry_type'],
            'benefit_type' => $attributes['benefit_type'],
            'quantity' => round((float) ($attributes['quantity'] ?? 0), 2),
            'amount' => round((float) ($attributes['amount'] ?? 0), 2),
            'status' => $attributes['status'] ?? 'posted',
            'reference' => $attributes['reference'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
            'reversed_entry_id' => $attributes['reversed_entry_id'] ?? null,
        ]);
    }

    private function balanceSnapshot(FlightMembershipPeriod $period): array
    {
        $entries = FlightMembershipBenefitLedger::query()
            ->where('flight_membership_period_id', $period->id)
            ->where('status', 'posted')
            ->get();

        return [
            'flights_available' => round((float) $entries->where('benefit_type', 'flight')->sum('quantity'), 2),
            'hours_available' => round((float) $entries->where('benefit_type', 'hour')->sum('quantity'), 2),
            'credit_available' => round((float) $entries->where('benefit_type', 'credit')->sum('amount'), 2),
        ];
    }

    private function syncMaterializedBalances(FlightMembershipPeriod $period): void
    {
        $entries = FlightMembershipBenefitLedger::query()
            ->where('flight_membership_period_id', $period->id)
            ->where('status', 'posted')
            ->get();

        $flightEntries = $entries->where('benefit_type', 'flight');
        $hourEntries = $entries->where('benefit_type', 'hour');
        $creditEntries = $entries->where('benefit_type', 'credit');

        $period->update([
            'granted_flights' => round((float) $flightEntries->filter(fn ($entry) => (float) $entry->quantity > 0)->sum('quantity'), 2),
            'granted_hours' => round((float) $hourEntries->filter(fn ($entry) => (float) $entry->quantity > 0)->sum('quantity'), 2),
            'granted_credit' => round((float) $creditEntries->filter(fn ($entry) => (float) $entry->amount > 0)->sum('amount'), 2),
            'used_flights' => round(abs((float) $flightEntries->filter(fn ($entry) => (float) $entry->quantity < 0)->sum('quantity')), 2),
            'used_hours' => round(abs((float) $hourEntries->filter(fn ($entry) => (float) $entry->quantity < 0)->sum('quantity')), 2),
            'used_credit' => round(abs((float) $creditEntries->filter(fn ($entry) => (float) $entry->amount < 0)->sum('amount')), 2),
        ]);
    }
}
