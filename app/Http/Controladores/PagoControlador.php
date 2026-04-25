<?php

namespace App\Http\Controladores;

use App\Modelos\Pago;
use App\Modelos\Reserva;
use Illuminate\Http\Request;

class PagoControlador extends ControladorBase
{
    public function index(Request $request)
    {
        return $this->ok([
            'payments' => Pago::where('user_id', $request->user()->id)->latest()->paginate(20),
        ]);
    }

    public function storeReservaPago(Request $request, Reserva $reservation)
    {
        abort_if($reservation->client_id !== $request->user()->id, 403, 'No puedes pagar esta reserva.');

        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:50'],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:pending,paid,failed,refunded'],
            'gateway_response' => ['nullable', 'array'],
            'failure_reason' => ['nullable', 'string'],
        ]);

        $payment = Pago::create([
            'user_id' => $request->user()->id,
            'reservation_id' => $reservation->id,
            'payment_type' => 'reservation',
            'amount' => $reservation->total_amount,
            'currency' => 'USD',
            'provider' => $data['provider'] ?? 'manual',
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'status' => $data['status'] ?? 'paid',
            'paid_at' => ($data['status'] ?? 'paid') === 'paid' ? now() : null,
            'failure_reason' => $data['failure_reason'] ?? null,
            'gateway_response' => $data['gateway_response'] ?? null,
        ]);

        if ($payment->status === 'paid') {
            $reservation->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        }

        return $this->ok(['payment' => $payment, 'reservation' => $reservation->fresh()], 201);
    }
}
