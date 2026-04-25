<?php

namespace App\Http\Controladores;

use App\Modelos\MetodoPago;
use Illuminate\Http\Request;

class MetodoPagoControlador extends ControladorBase
{
    public function index(Request $request)
    {
        return $this->ok(['payment_methods' => $request->user()->paymentMethods()->latest()->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:50'],
            'brand' => ['nullable', 'string', 'max:50'],
            'last_four' => ['nullable', 'string', 'size:4'],
            'provider' => ['nullable', 'string', 'max:50'],
            'provider_payment_method_id' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_default'] ?? false) === true) {
            $request->user()->paymentMethods()->update(['is_default' => false]);
        }

        $method = $request->user()->paymentMethods()->create($data + ['provider' => 'manual']);

        return $this->ok(['payment_method' => $method], 201);
    }

    public function destroy(Request $request, MetodoPago $paymentMethod)
    {
        abort_if($paymentMethod->user_id !== $request->user()->id, 403);
        $paymentMethod->delete();

        return $this->ok(['message' => 'Metodo de pago eliminado.']);
    }
}
