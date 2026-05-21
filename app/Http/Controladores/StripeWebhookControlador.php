<?php

namespace App\Http\Controladores;

use App\Modelos\Pago;
use App\Modelos\SolicitudVuelo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Webhook;
use Throwable;

class StripeWebhookControlador extends ControladorBase
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature');
        $secret = (string) config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook invalido',
            ], 400);
        }

        $existing = DB::table('webhook_events')
            ->where('provider', 'stripe')
            ->where('event_id', $event->id)
            ->first();

        if ($existing && $existing->status === 'processed') {
            return response()->json(['success' => true, 'received' => true]);
        }

        $stripeCreatedAtUtc = Carbon::createFromTimestamp((int) $event->created)->utc();
        $stripeCreatedAtLocal = $stripeCreatedAtUtc->copy()->setTimezone(config('app.timezone', 'UTC'));

        if (! $existing) {
            DB::table('webhook_events')->insert([
                'provider' => 'stripe',
                'event_id' => $event->id,
                'event_type' => $event->type,
                'payload' => json_decode($payload, true),
                'stripe_created_at_utc' => $stripeCreatedAtUtc,
                'stripe_created_at_local' => $stripeCreatedAtLocal,
                'status' => 'received',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('webhook_events')
                ->where('provider', 'stripe')
                ->where('event_id', $event->id)
                ->update([
                    'stripe_created_at_utc' => $stripeCreatedAtUtc,
                    'stripe_created_at_local' => $stripeCreatedAtLocal,
                    'updated_at' => now(),
                ]);
        }

        try {
            match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
                'checkout.session.expired' => $this->handleCheckoutExpired($event->data->object),
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
                'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
                'payment_intent.canceled' => $this->handlePaymentCanceled($event->data->object),
                default => null,
            };

            DB::table('webhook_events')
                ->where('provider', 'stripe')
                ->where('event_id', $event->id)
                ->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'updated_at' => now(),
                    'error_message' => null,
                ]);
        } catch (Throwable $exception) {
            DB::table('webhook_events')
                ->where('provider', 'stripe')
                ->where('event_id', $event->id)
                ->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        return response()->json(['success' => true, 'received' => true]);
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $flightRequestId = (int) ($session->metadata->flight_request_id ?? 0);
        if (! $flightRequestId) {
            return;
        }

        $flightRequest = SolicitudVuelo::find($flightRequestId);
        if (! $flightRequest) {
            return;
        }

        DB::transaction(function () use ($flightRequest, $session) {
            $flightRequest->update([
                'payment_method' => 'card',
                'payment_status' => 'paid',
                'stripe_checkout_session_id' => $session->id,
                'stripe_payment_intent_id' => $session->payment_intent ?? null,
                'workflow_status' => 'pago confirmado',
                'status' => 'reserved',
            ]);

            Pago::updateOrCreate(
                [
                    'flight_request_id' => $flightRequest->id,
                    'provider' => 'stripe',
                    'payment_type' => 'reservation',
                ],
                [
                    'user_id' => $flightRequest->client_id,
                    'amount' => ((int) ($session->amount_total ?? 0)) / 100,
                    'currency' => strtoupper((string) ($session->currency ?? $flightRequest->currency ?? 'USD')),
                    'transaction_reference' => $session->id,
                    'stripe_checkout_session_id' => $session->id,
                    'stripe_payment_intent_id' => $session->payment_intent ?? null,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'failure_reason' => null,
                    'gateway_response' => json_decode(json_encode($session), true),
                ],
            );
        });
    }

    private function handleCheckoutExpired(object $session): void
    {
        $this->markFlightRequestPaymentFailure(
            checkoutSessionId: (string) ($session->id ?? ''),
            paymentIntentId: null,
            flightRequestId: (int) ($session->metadata->flight_request_id ?? 0),
            status: 'cancelled',
            paymentStatus: 'cancelled',
            reason: 'Checkout expirado.',
        );
    }

    private function handlePaymentIntentSucceeded(object $paymentIntent): void
    {
        $flightRequestId = (int) ($paymentIntent->metadata->flight_request_id ?? 0);
        if (! $flightRequestId) {
            return;
        }

        $flightRequest = SolicitudVuelo::find($flightRequestId);
        if (! $flightRequest) {
            return;
        }

        DB::transaction(function () use ($flightRequest, $paymentIntent) {
            $flightRequest->update([
                'payment_method' => 'card',
                'payment_status' => 'paid',
                'stripe_payment_intent_id' => $paymentIntent->id,
                'workflow_status' => 'pago confirmado',
                'status' => 'reserved',
            ]);

            Pago::updateOrCreate(
                [
                    'flight_request_id' => $flightRequest->id,
                    'provider' => 'stripe',
                    'payment_type' => 'reservation',
                ],
                [
                    'user_id' => $flightRequest->client_id,
                    'amount' => ((int) ($paymentIntent->amount ?? 0)) / 100,
                    'currency' => strtoupper((string) ($paymentIntent->currency ?? $flightRequest->currency ?? 'USD')),
                    'transaction_reference' => $paymentIntent->id,
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'failure_reason' => null,
                    'gateway_response' => json_decode(json_encode($paymentIntent), true),
                ],
            );
        });
    }

    private function handlePaymentFailed(object $paymentIntent): void
    {
        $flightRequestId = (int) ($paymentIntent->metadata->flight_request_id ?? 0);
        $message = $paymentIntent->last_payment_error->message ?? 'Pago rechazado por Stripe.';

        $this->markFlightRequestPaymentFailure(
            checkoutSessionId: '',
            paymentIntentId: (string) ($paymentIntent->id ?? ''),
            flightRequestId: $flightRequestId,
            status: 'failed',
            paymentStatus: 'failed',
            reason: (string) $message,
        );
    }

    private function handlePaymentCanceled(object $paymentIntent): void
    {
        $this->markFlightRequestPaymentFailure(
            checkoutSessionId: '',
            paymentIntentId: (string) ($paymentIntent->id ?? ''),
            flightRequestId: (int) ($paymentIntent->metadata->flight_request_id ?? 0),
            status: 'cancelled',
            paymentStatus: 'cancelled',
            reason: 'PaymentIntent cancelado.',
        );
    }

    private function markFlightRequestPaymentFailure(
        string $checkoutSessionId,
        ?string $paymentIntentId,
        int $flightRequestId,
        string $status,
        string $paymentStatus,
        string $reason,
    ): void {
        $paymentQuery = Pago::query()->where('provider', 'stripe');

        if ($flightRequestId > 0) {
            $paymentQuery->where('flight_request_id', $flightRequestId);
        } elseif ($checkoutSessionId !== '') {
            $paymentQuery->where('stripe_checkout_session_id', $checkoutSessionId);
        } elseif ($paymentIntentId) {
            $paymentQuery->where('stripe_payment_intent_id', $paymentIntentId);
        } else {
            return;
        }

        $payment = $paymentQuery->latest('id')->first();
        $flightRequest = $flightRequestId > 0
            ? SolicitudVuelo::find($flightRequestId)
            : ($payment?->flightRequest);

        if ($payment) {
            $payment->update([
                'status' => $status,
                'failure_reason' => $reason,
                'stripe_payment_intent_id' => $paymentIntentId ?: $payment->stripe_payment_intent_id,
                'paid_at' => null,
            ]);
        }

        if ($flightRequest) {
            $flightRequest->update([
                'payment_status' => $paymentStatus,
                'stripe_payment_intent_id' => $paymentIntentId ?: $flightRequest->stripe_payment_intent_id,
                'workflow_status' => 'pago pendiente',
                'status' => 'reserved',
            ]);
        }
    }
}
