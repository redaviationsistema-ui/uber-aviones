<?php

namespace App\Http\Controladores;

use App\Modelos\ChecklistItem;
use App\Modelos\ChecklistOperacion;
use App\Modelos\Notificacion;
use App\Modelos\Operacion;
use App\Modelos\Pago;
use App\Modelos\Reserva;
use App\Servicios\Aeronaves\AircraftAvailabilityService;
use JsonException;
use Illuminate\Http\Request;

class PagoControlador extends ControladorBase
{
    public function __construct(private readonly AircraftAvailabilityService $aircraftAvailabilityService)
    {
    }

    public function index(Request $request)
    {
        return $this->ok([
            'payments' => Pago::with(['reservation', 'reservation.contract'])
                ->where('user_id', $request->user()->id)
                ->latest()
                ->paginate(20),
        ]);
    }

    public function storeReservaPago(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);
        abort_if($reservation->client_id !== $request->user()->id, 403, 'No puedes pagar esta reserva.');
        abort_if(! $this->reservationContractIsSigned($reservation), 409, 'Primero debes firmar el contrato.');

        $data = $request->validate([
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'provider' => ['nullable', 'string', 'max:50'],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:pending,paid,failed,refunded'],
            'gateway_response' => ['nullable', 'array'],
            'failure_reason' => ['nullable', 'string'],
        ]);

        if (isset($data['payment_method_id'])) {
            abort_if(
                ! $request->user()->paymentMethods()->whereKey($data['payment_method_id'])->exists(),
                403,
                'No puedes usar este metodo de pago.'
            );
        }

        $payment = $reservation->payments()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['pending', 'failed'])
            ->latest('id')
            ->first();

        if ($payment) {
            $payment->update([
                'payment_method_id' => $data['payment_method_id'] ?? $payment->payment_method_id,
                'provider' => $data['provider'] ?? $payment->provider ?? 'manual',
                'transaction_reference' => $data['transaction_reference'] ?? $payment->transaction_reference,
                'status' => $data['status'] ?? 'paid',
                'paid_at' => ($data['status'] ?? 'paid') === 'paid' ? now() : null,
                'failure_reason' => $data['failure_reason'] ?? null,
                'gateway_response' => $data['gateway_response'] ?? null,
            ]);
        } else {
            $payment = Pago::create([
                'user_id' => $request->user()->id,
                'reservation_id' => $reservation->id,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'payment_type' => 'reservation',
                'amount' => $reservation->total_amount,
                'currency' => $reservation->currency ?? 'USD',
                'provider' => $data['provider'] ?? 'manual',
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'status' => $data['status'] ?? 'paid',
                'paid_at' => ($data['status'] ?? 'paid') === 'paid' ? now() : null,
                'failure_reason' => $data['failure_reason'] ?? null,
                'gateway_response' => $data['gateway_response'] ?? null,
            ]);
        }

        if ($payment->status === 'paid') {
            $reservation->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            $this->aircraftAvailabilityService->blockAircraftForPaidReservation($reservation->fresh(['flightRequest.legs', 'legs']));
            $this->notifyAssignedCrew($reservation);
        } elseif (in_array($payment->status, ['failed', 'refunded'], true)) {
            $reservation->update(['status' => 'pending_payment']);
            $this->aircraftAvailabilityService->releaseReservationBlock($reservation->fresh(['flightRequest', 'latestPayment']));
        }

        return $this->ok(['payment' => $payment->fresh(), 'reservation' => $reservation->fresh(['payments', 'contract'])], 201);
    }

    public function retryReservaPago(Request $request, mixed $reservation)
    {
        $reservation = $this->resolveReservation($reservation);
        abort_if($reservation->client_id !== $request->user()->id, 403, 'No puedes reintentar este pago.');

        $payment = $reservation->payments()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        abort_if(! $payment, 404, 'No existe una orden de pago previa para esta reserva.');

        $payment->update([
            'status' => 'pending',
            'failure_reason' => null,
            'gateway_response' => null,
            'paid_at' => null,
        ]);

        $reservation->update(['status' => 'pending_payment']);
        $this->aircraftAvailabilityService->releaseReservationBlock($reservation->fresh(['flightRequest', 'latestPayment']));

        return $this->ok([
            'payment' => $payment->fresh(),
            'reservation' => $reservation->fresh(['payments']),
        ]);
    }

    private function reservationContractIsSigned(Reserva $reservation): bool
    {
        $contract = $reservation->contract;
        if (! $contract) {
            return false;
        }

        $contractStatus = strtolower(trim((string) ($contract->status ?? '')));
        $docusignStatus = strtolower(trim((string) ($contract->docusign_status ?? '')));

        if (in_array($contractStatus, ['signed', 'completed', 'approved'], true)) {
            return true;
        }

        if (in_array($docusignStatus, ['completed', 'signed', 'approved'], true)) {
            return true;
        }

        return filled($contract->signed_pdf_path) || filled($contract->document_url);
    }

    private function notifyAssignedCrew(Reserva $reservation): void
    {
        $operation = Operacion::where('flight_request_id', $reservation->flight_request_id)->latest('id')->first();

        if (! $operation?->sobrecargo_user_id) {
            return;
        }

        Notificacion::create([
            'user_id' => $operation->sobrecargo_user_id,
            'type' => 'service_assignment',
            'title' => 'Servicio confirmado',
            'message' => 'La reserva fue confirmada y ya puedes revisar detalles del vuelo.',
            'data' => [
                'reservation_id' => $reservation->id,
                'operation_id' => $operation->id,
            ],
        ]);

        $checklist = ChecklistOperacion::firstOrCreate(
            [
                'operation_id' => $operation->id,
                'sobrecargo_user_id' => $operation->sobrecargo_user_id,
                'type' => 'preflight',
            ],
            [
                'status' => 'pendiente',
            ]
        );

        if (! $checklist->items()->exists()) {
            foreach ([
                'Revisar briefing y ruta',
                'Confirmar pasajeros autorizados',
                'Validar catering y amenidades',
                'Confirmar cabina lista para salida',
            ] as $label) {
                ChecklistItem::create([
                    'checklist_id' => $checklist->id,
                    'label' => $label,
                    'is_completed' => false,
                ]);
            }
        }
    }

    private function resolveReservation(mixed $identifier): Reserva
    {
        if ($identifier instanceof Reserva) {
            return $identifier->load(['contract', 'payments']);
        }

        $normalizedIdentifier = $this->normalizeReservationIdentifier($identifier);

        return Reserva::with(['contract', 'payments'])
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
}
