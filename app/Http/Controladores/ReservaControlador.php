<?php

namespace App\Http\Controladores;

use App\Modelos\CalificacionServicio;
use App\Modelos\ContratoReserva;
use App\Modelos\Comision;
use App\Modelos\Cotizacion;
use App\Modelos\Reserva;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReservaControlador extends ControladorBase
{
    public function index(Request $request)
    {
        $query = Reserva::with(['quote', 'aircraft', 'provider.user'])->latest();

        if ($request->user()->hasRole('client') && ! $request->user()->hasRole('admin')) {
            $query->where('client_id', $request->user()->id);
        }

        return $this->ok(['reservations' => $query->paginate(20)]);
    }

    public function providerIndex(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404);

        return $this->ok([
            'reservations' => Reserva::with(['quote', 'aircraft', 'client'])
                ->where('provider_id', $provider->id)
                ->latest()
                ->paginate(20),
        ]);
    }

    public function show(Request $request, Reserva $reservation)
    {
        if ($request->user()->hasRole('client') && ! $request->user()->hasRole('admin')) {
            abort_if($reservation->client_id !== $request->user()->id, 403);
        }

        if ($request->user()->hasRole('provider') && ! $request->user()->hasRole('admin')) {
            abort_if($reservation->provider_id !== $request->user()->provider?->id, 403);
        }

        return $this->ok(['reservation' => $reservation->load(['quote', 'aircraft', 'provider', 'legs', 'contract', 'review', 'payments'])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['quote_id' => ['required', 'exists:quotes,id']]);
        $quote = Cotizacion::with('flightRequest')->findOrFail($data['quote_id']);

        abort_if($quote->flightRequest->client_id !== $request->user()->id, 403, 'No puedes reservar esta cotizacion.');
        abort_if($quote->status !== 'accepted', 409, 'Primero debes aceptar la cotizacion.');

        $reservation = Reserva::create([
            'client_id' => $request->user()->id,
            'provider_id' => $quote->provider_id,
            'aircraft_id' => $quote->aircraft_id,
            'flight_request_id' => $quote->flight_request_id,
            'quote_id' => $quote->id,
            'reservation_code' => 'PV-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'status' => 'pending_payment',
            'total_amount' => $quote->total,
            'currency' => $quote->currency ?? 'USD',
        ]);

        Comision::create([
            'reservation_id' => $reservation->id,
            'provider_id' => $quote->provider_id,
            'platform_fee' => round(((float) $quote->total) * 0.10, 2),
            'provider_amount' => round(((float) $quote->total) * 0.90, 2),
            'status' => 'held',
        ]);

        $quote->flightRequest->update(['status' => 'reserved']);
        $this->buildReservationContract($reservation);
        $this->writeAudit($request, 'create', 'reservations', 'Reserva creada desde cotizacion aceptada.');

        return $this->ok(['reservation' => $reservation->load(['quote', 'aircraft', 'contract'])], 201);
    }

    public function showContract(Request $request, Reserva $reservation)
    {
        $this->authorizeReservationClient($request, $reservation);

        return $this->ok([
            'contract' => $this->buildReservationContract($reservation)->fresh(),
            'reservation' => $reservation->load(['quote', 'aircraft', 'provider']),
        ]);
    }

    public function generateContract(Request $request, Reserva $reservation)
    {
        $this->authorizeReservationClient($request, $reservation);

        $contract = $this->buildReservationContract($reservation, true);
        $this->writeAudit($request, 'generate', 'reservation_contracts', 'Contrato de reserva generado.');

        return $this->ok([
            'contract' => $contract->fresh(),
            'reservation' => $reservation->fresh(),
        ]);
    }

    public function signContract(Request $request, Reserva $reservation)
    {
        $this->authorizeReservationClient($request, $reservation);

        $contract = $this->buildReservationContract($reservation);
        $contract->update([
            'status' => 'signed',
            'signed_by_user_id' => $request->user()->id,
            'signed_at' => now(),
        ]);

        $paymentOrder = $reservation->payments()
            ->whereIn('status', ['pending', 'failed'])
            ->latest('id')
            ->first();

        if (! $paymentOrder) {
            $paymentOrder = $reservation->payments()->create([
                'user_id' => $request->user()->id,
                'payment_type' => 'reservation',
                'amount' => $reservation->total_amount,
                'currency' => $reservation->currency ?? 'USD',
                'provider' => 'manual',
                'status' => 'pending',
                'transaction_reference' => 'PAY-'.Str::upper(Str::random(10)),
            ]);
        }

        $this->writeAudit($request, 'sign', 'reservation_contracts', 'Contrato firmado por cliente.');

        return $this->ok([
            'contract' => $contract->fresh(),
            'payment_order' => $paymentOrder->fresh(),
            'reservation' => $reservation->fresh(['contract', 'payments']),
        ]);
    }

    public function rateService(Request $request, Reserva $reservation)
    {
        $this->authorizeReservationClient($request, $reservation);
        abort_if($reservation->status !== 'completed', 409, 'Solo puedes calificar servicios finalizados.');

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string'],
        ]);

        $review = CalificacionServicio::updateOrCreate(
            [
                'reservation_id' => $reservation->id,
                'user_id' => $request->user()->id,
            ],
            [
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
                'submitted_at' => now(),
            ]
        );

        $this->writeAudit($request, 'create', 'service_reviews', 'Cliente califico el servicio.');

        return $this->ok([
            'review' => $review,
            'reservation' => $reservation->fresh(['review']),
        ]);
    }

    private function authorizeReservationClient(Request $request, Reserva $reservation): void
    {
        abort_if($reservation->client_id !== $request->user()->id && ! $request->user()->hasRole('admin'), 403);
    }

    private function buildReservationContract(Reserva $reservation, bool $regenerate = false): ContratoReserva
    {
        $existing = $reservation->contract;

        if ($existing && ! $regenerate) {
            return $existing;
        }

        $payload = [
            'contract_code' => $existing?->contract_code ?? 'CTR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'status' => 'generated',
            'generated_at' => now(),
            'signed_by_user_id' => null,
            'signed_at' => null,
            'terms_snapshot' => [
                'reservation_code' => $reservation->reservation_code,
                'amount' => $reservation->total_amount,
                'currency' => $reservation->currency,
                'aircraft_id' => $reservation->aircraft_id,
                'provider_id' => $reservation->provider_id,
                'conditions' => [
                    'Pago requerido antes de confirmacion final.',
                    'Operacion sujeta a condiciones de seguridad y slot.',
                    'Cualquier cambio relevante queda registrado en historial operativo.',
                ],
            ],
        ];

        if ($existing) {
            $existing->update($payload);
            return $existing;
        }

        return $reservation->contract()->create($payload);
    }
}
