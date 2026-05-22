<?php

namespace App\Http\Controladores;

use App\Modelos\CalificacionServicio;
use App\Modelos\ContratoReserva;
use App\Modelos\Comision;
use App\Modelos\Cotizacion;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use JsonException;
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

    public function show(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);

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
        $data = $request->validate([
            'quote_id' => ['nullable', 'exists:quotes,id'],
            'flight_request_id' => ['nullable', 'exists:flight_requests,id'],
        ]);

        abort_if(
            ! ($data['quote_id'] ?? null) && ! ($data['flight_request_id'] ?? null),
            422,
            'Debes enviar quote_id o flight_request_id para crear la reserva.'
        );

        if ($data['quote_id'] ?? null) {
            $quote = Cotizacion::with('flightRequest')->findOrFail($data['quote_id']);

            abort_if($quote->flightRequest->client_id !== $request->user()->id, 403, 'No puedes reservar esta cotizacion.');
            abort_if($quote->status !== 'accepted', 409, 'Primero debes aceptar la cotizacion.');

            $reservation = Reserva::firstOrCreate(
                ['quote_id' => $quote->id],
                [
                    'client_id' => $request->user()->id,
                    'provider_id' => $quote->provider_id,
                    'aircraft_id' => $quote->aircraft_id,
                    'flight_request_id' => $quote->flight_request_id,
                    'reservation_code' => 'PV-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                    'status' => 'pending_payment',
                    'total_amount' => $quote->total,
                    'currency' => $quote->currency ?? 'USD',
                ]
            );

            $quote->flightRequest->update(['status' => 'reserved']);
        } else {
            $flightRequest = SolicitudVuelo::with(['matches', 'quotes'])
                ->findOrFail($data['flight_request_id']);

            abort_if($flightRequest->client_id !== $request->user()->id, 403, 'No puedes reservar esta solicitud.');

            $reservation = Reserva::where('flight_request_id', $flightRequest->id)
                ->latest('id')
                ->first();

            if (! $reservation) {
                abort_if(
                    ! $flightRequest->assigned_provider_id || ! $flightRequest->assigned_aircraft_id,
                    409,
                    'La solicitud aun no tiene proveedor y aeronave confirmados.'
                );

                $acceptedQuote = $flightRequest->quotes()
                    ->where('status', 'accepted')
                    ->latest('id')
                    ->first();

                $amount = (float) (
                    $acceptedQuote?->total
                    ?? $flightRequest->final_price
                    ?? data_get($flightRequest->pricing_context, 'final_price')
                    ?? data_get($flightRequest->pricing_context, 'total')
                    ?? 0
                );

                abort_if($amount <= 0, 422, 'La solicitud no tiene un monto valido para crear la reserva.');

                $reservation = Reserva::create([
                    'client_id' => $request->user()->id,
                    'provider_id' => $acceptedQuote?->provider_id ?? $flightRequest->assigned_provider_id,
                    'aircraft_id' => $acceptedQuote?->aircraft_id ?? $flightRequest->assigned_aircraft_id,
                    'flight_request_id' => $flightRequest->id,
                    'quote_id' => $acceptedQuote?->id,
                    'reservation_code' => 'PV-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                    'status' => 'pending_payment',
                    'total_amount' => $amount,
                    'currency' => $acceptedQuote?->currency ?? $flightRequest->currency ?? 'USD',
                ]);
            }

            $flightRequest->update([
                'status' => 'reserved',
                'workflow_status' => $flightRequest->workflow_status ?: 'contrato pendiente',
            ]);
        }

        Comision::firstOrCreate(
            ['reservation_id' => $reservation->id],
            [
                'provider_id' => $reservation->provider_id,
                'platform_fee' => round(((float) $reservation->total_amount) * 0.10, 2),
                'provider_amount' => round(((float) $reservation->total_amount) * 0.90, 2),
                'status' => 'held',
            ]
        );
        $this->buildReservationContract($reservation);
        $this->writeAudit($request, 'create', 'reservations', 'Reserva creada o recuperada para el flujo del cliente.');

        return $this->ok(['reservation' => $reservation->load(['quote', 'aircraft', 'contract'])], 201);
    }

    public function showContract(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);
        $this->authorizeReservationClient($request, $reservation);

        return $this->ok([
            'contract' => $this->buildReservationContract($reservation)->fresh(),
            'reservation' => $reservation->load(['quote', 'aircraft', 'provider']),
        ]);
    }

    public function generateContract(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);
        $this->authorizeReservationClient($request, $reservation);

        $contract = $this->buildReservationContract($reservation, true);
        $this->writeAudit($request, 'generate', 'reservation_contracts', 'Contrato de reserva generado.');

        return $this->ok([
            'contract' => $contract->fresh(),
            'reservation' => $reservation->fresh(),
        ]);
    }

    public function signContract(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);
        $this->authorizeReservationClient($request, $reservation);

        $contract = $this->buildReservationContract($reservation);
        $signaturePayload = $request->input('signature');
        $contractSnapshotPayload = $request->input('contract_snapshot');
        $termsSnapshot = is_array($contract->terms_snapshot) ? $contract->terms_snapshot : [];

        if (is_array($contractSnapshotPayload) && ! empty($contractSnapshotPayload)) {
            $termsSnapshot['client_contract_snapshot'] = $contractSnapshotPayload;
        }

        if (is_array($signaturePayload) && filled($signaturePayload['data_url'] ?? null)) {
            $termsSnapshot['client_signature'] = [
                'name' => $signaturePayload['name'] ?? 'firma.png',
                'mime_type' => $signaturePayload['mime_type'] ?? 'image/png',
                'size' => (int) ($signaturePayload['size'] ?? 0),
                'data_url' => $signaturePayload['data_url'],
            ];
        }

        $contract->update([
            'status' => 'signed',
            'signed_by_user_id' => $request->user()->id,
            'signed_at' => now(),
            'terms_snapshot' => $termsSnapshot,
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

    public function rateService(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);
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

    private function resolveReservation(mixed $identifier): Reserva
    {
        if ($identifier instanceof Reserva) {
            return $identifier->load(['quote', 'aircraft', 'provider', 'legs', 'contract', 'review', 'payments']);
        }

        $normalizedIdentifier = $this->normalizeReservationIdentifier($identifier);

        return Reserva::with(['quote', 'aircraft', 'provider', 'legs', 'contract', 'review', 'payments'])
            ->where('id', $normalizedIdentifier)
            ->orWhere('flight_request_id', $normalizedIdentifier)
            ->latest('id')
            ->firstOrFail();
    }

    private function normalizeReservationIdentifier(mixed $value): string
    {
        if ($value instanceof Reserva) {
            return (string) $value->getKey();
        }

        if (is_array($value)) {
            return $this->normalizeReservationIdentifier(
                $value['id'] ?? $value['reservation_id'] ?? $value['flight_request_id'] ?? ''
            );
        }

        if (is_object($value)) {
            return $this->normalizeReservationIdentifier(
                $value->id ?? $value->reservation_id ?? $value->flight_request_id ?? ''
            );
        }

        $normalizedValue = trim((string) $value);

        if ($normalizedValue === '') {
            return '';
        }

        if (str_starts_with($normalizedValue, '{') || str_starts_with($normalizedValue, '[')) {
            try {
                $decoded = json_decode($normalizedValue, true, 512, JSON_THROW_ON_ERROR);

                return $this->normalizeReservationIdentifier($decoded);
            } catch (JsonException) {
                return $normalizedValue;
            }
        }

        return $normalizedValue;
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
