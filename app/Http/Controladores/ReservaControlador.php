<?php

namespace App\Http\Controladores;

use App\Modelos\CalificacionServicio;
use App\Modelos\ContratoReserva;
use App\Modelos\Comision;
use App\Modelos\Cotizacion;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use Barryvdh\DomPDF\Facade\Pdf;
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

    public function downloadContractPdf(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);
        $this->authorizeReservationClient($request, $reservation);

        $contract = $this->buildReservationContract($reservation)->fresh();
        $fileName = ($contract->contract_code ?: 'contrato-'.$reservation->id).'.pdf';
        $pdf = Pdf::loadView('pdf.contract', $this->buildContractPdfPayload($reservation, $contract))
            ->setPaper('a4');

        return $pdf->download($fileName);
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

        if ($reservation->flight_request_id) {
            $reservation->flightRequest()->update([
                'status' => 'reserved',
                'workflow_status' => 'pago pendiente',
                'payment_status' => 'pending',
            ]);
        }

        $reservation->update([
            'status' => 'pending_payment',
        ]);

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

    private function buildContractPdfPayload(Reserva $reservation, ContratoReserva $contract): array
    {
        $snapshot = is_array($contract->terms_snapshot) ? $contract->terms_snapshot : [];
        $clientSnapshot = is_array($snapshot['client_contract_snapshot'] ?? null)
            ? $snapshot['client_contract_snapshot']
            : [];
        $conditions = array_values(array_filter(
            $snapshot['conditions'] ?? [],
            fn ($value) => is_string($value) && trim($value) !== ''
        ));
        $segments = $this->buildPdfItinerarySegments($reservation, $clientSnapshot);
        $route = $this->buildPdfRouteLabel($segments, $clientSnapshot['route'] ?? '');
        $finalPriceValue = $this->parsePdfMoney($clientSnapshot['final_price'] ?? $snapshot['amount'] ?? $reservation->total_amount);
        $depositValue = $this->parsePdfMoney($clientSnapshot['deposit_amount'] ?? null);
        if ($depositValue <= 0 && $finalPriceValue > 0) {
            $depositValue = $finalPriceValue * 0.5;
        }

        $pricingRows = [
            ['label' => 'Costo total del servicio', 'amount' => $finalPriceValue],
            ['label' => 'Depósito requerido', 'amount' => $depositValue],
            ['label' => 'Saldo estimado', 'amount' => max($finalPriceValue - $depositValue, 0)],
        ];

        return [
            'contract' => $contract,
            'reservation' => $reservation->loadMissing(['client', 'provider', 'aircraft', 'legs', 'flightRequest']),
            'snapshot' => $snapshot,
            'clientSnapshot' => $clientSnapshot,
            'conditions' => $conditions,
            'segments' => $segments,
            'route' => $route,
            'departureDate' => $clientSnapshot['departure_date'] ?? optional($reservation->flightRequest)->departure_datetime?->format('d/m/Y H:i') ?? 'Pendiente',
            'aircraft' => $clientSnapshot['aircraft'] ?? optional($reservation->aircraft)->model ?? 'Por definir',
            'aircraftCategory' => $clientSnapshot['aircraft_category'] ?? optional($reservation->aircraft)->category ?? 'Por definir',
            'passengers' => $clientSnapshot['passengers'] ?? ($reservation->flightRequest?->passengers ? $reservation->flightRequest->passengers.' pasajeros' : 'Por definir'),
            'customerName' => $clientSnapshot['customer_name'] ?? optional($reservation->client)->name ?? 'Cliente',
            'customerRepresentative' => $clientSnapshot['customer_representative'] ?? optional($reservation->client)->name ?? 'Cliente',
            'customerAddress' => $clientSnapshot['customer_address'] ?? 'Domicilio por confirmar',
            'serviceTier' => $clientSnapshot['service_tier'] ?? 'Servicio ejecutivo privado',
            'operator' => $clientSnapshot['operator'] ?? optional($reservation->provider)->commercial_name ?? optional($reservation->provider)->company_name ?? 'Operador por confirmar',
            'contractDate' => $clientSnapshot['contract_date'] ?? optional($contract->signed_at ?: $contract->generated_at)->format('d/m/Y') ?? now()->format('d/m/Y'),
            'finalPrice' => $this->formatPdfMoney($finalPriceValue),
            'depositText' => $depositValue > 0 ? $this->formatPdfMoney($depositValue).' (50% del costo total)' : 'Depósito por definir',
            'balanceText' => $this->formatPdfMoney(max($finalPriceValue - $depositValue, 0)),
            'pricingRows' => $pricingRows,
            'logoPath' => public_path('logo.png'),
            'providerSignaturePath' => public_path('AUTOGRAFO/AUTOGRAFO JEFE.png'),
            'clientSignatureDataUrl' => data_get($snapshot, 'client_signature.data_url', ''),
            'bankAccounts' => [
                [
                    'bank' => 'BANBAJÍO',
                    'account' => '046 76313 20201',
                    'clabe' => '0304 209000 4337 2636',
                    'beneficiary' => 'TRANSPORTACIÓN EXITOSA BELLIKAI S.A. DE C.V.',
                    'rfc' => 'TEB231030NU9',
                ],
                [
                    'bank' => 'BANREGIO',
                    'account' => '247 96234 0011',
                    'clabe' => '05842 0000 150761410',
                    'beneficiary' => 'TRANSPORTACIÓN EXITOSA BELLIKAI S.A. DE C.V.',
                    'rfc' => 'TEB231030NU9',
                ],
                [
                    'bank' => 'BBVA',
                    'account' => '0122 912627',
                    'clabe' => '01243 800122 9126272',
                    'beneficiary' => 'TRANSPORTACIÓN EXITOSA BELLIKAI S.A. DE C.V.',
                    'rfc' => 'TEB231030NU9',
                ],
            ],
            'includesItems' => [
                'Aeronave y tripulación asignada para la ruta contratada.',
                'Coordinación operativa y seguimiento comercial de SKY Group / Red Aviation.',
                'Combustible y operación contemplados en la cotización validada.',
                'Uso de aeronave conforme al itinerario confirmado en este Anexo A.',
            ],
            'excludesItems' => [
                'Catering especial no contemplado expresamente.',
                'Transporte terrestre, hospedaje o concierge fuera del alcance contratado.',
                'Cambios de itinerario solicitados por el Cliente después de la firma.',
                'Tiempos de espera extraordinarios, permisos especiales o costos por reprogramación.',
            ],
        ];
    }

    private function buildPdfItinerarySegments(Reserva $reservation, array $clientSnapshot): array
    {
        $segments = [];
        $snapshotSegments = $clientSnapshot['itinerary_segments'] ?? [];
        if (is_array($snapshotSegments) && ! empty($snapshotSegments)) {
            foreach ($snapshotSegments as $index => $segment) {
                if (! is_array($segment)) {
                    continue;
                }

                $segments[] = [
                    'order' => $segment['order'] ?? $index + 1,
                    'origin' => $segment['origin'] ?? 'Origen por confirmar',
                    'destination' => $segment['destination'] ?? 'Destino por confirmar',
                    'departure' => $segment['departure'] ?? '',
                ];
            }

            return $segments;
        }

        foreach ($reservation->legs as $index => $leg) {
            $segments[] = [
                'order' => $leg->leg_order ?? $index + 1,
                'origin' => $leg->origin ?? 'Origen por confirmar',
                'destination' => $leg->destination ?? 'Destino por confirmar',
                'departure' => (string) ($leg->departure_datetime ?? ''),
            ];
        }

        if ($segments) {
            return $segments;
        }

        return [[
            'order' => 1,
            'origin' => $reservation->flightRequest?->origin ?? 'Origen por confirmar',
            'destination' => $reservation->flightRequest?->destination ?? 'Destino por confirmar',
            'departure' => (string) ($reservation->flightRequest?->departure_datetime ?? ''),
        ]];
    }

    private function buildPdfRouteLabel(array $segments, string $fallback = ''): string
    {
        $path = [];
        foreach ($segments as $index => $segment) {
            $origin = trim((string) ($segment['origin'] ?? ''));
            $destination = trim((string) ($segment['destination'] ?? ''));
            if ($index === 0 && $origin !== '') {
                $path[] = $origin;
            }
            if ($destination !== '' && end($path) !== $destination) {
                $path[] = $destination;
            }
        }

        if (count($path) >= 2) {
            return implode(' → ', $path);
        }

        return trim($fallback) !== '' ? $fallback : 'Ruta por confirmar';
    }

    private function parsePdfMoney(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^\d,.\-]/', '', trim((string) $value)) ?? '';
        if ($normalized === '') {
            return 0.0;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = strrpos($normalized, ',') > strrpos($normalized, '.')
                ? str_replace('.', '', $normalized)
                : str_replace(',', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',') && ! str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function formatPdfMoney(float $amount): string
    {
        return '$'.number_format($amount, 2, '.', ',').' USD';
    }
}
