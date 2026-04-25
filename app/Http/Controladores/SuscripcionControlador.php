<?php

namespace App\Http\Controladores;

use App\Modelos\Pago;
use App\Modelos\Plan;
use App\Modelos\Suscripcion;
use Illuminate\Http\Request;

class SuscripcionControlador extends ControladorBase
{
    public function status(Request $request)
    {
        return $this->ok(['access' => $request->user()->accessStatus()]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'payment_provider' => ['nullable', 'string', 'max:50'],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);
        $expiresAt = $plan->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth();

        $request->user()->subscriptions()->where('status', 'active')->update(['status' => 'cancelled']);

        $subscription = Suscripcion::create([
            'user_id' => $request->user()->id,
            'plan_id' => $plan->id,
            'started_at' => now(),
            'expires_at' => $expiresAt,
            'status' => 'active',
            'payment_status' => 'paid',
        ]);

        Pago::create([
            'user_id' => $request->user()->id,
            'subscription_id' => $subscription->id,
            'payment_type' => 'subscription',
            'amount' => $plan->price,
            'currency' => 'USD',
            'provider' => $data['payment_provider'] ?? 'manual',
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'status' => 'paid',
        ]);

        return $this->ok(['subscription' => $subscription->load('plan')], 201);
    }

    public function cancel(Request $request)
    {
        $request->user()->subscriptions()->where('status', 'active')->update(['status' => 'cancelled']);

        return $this->ok(['message' => 'Suscripcion cancelada.']);
    }
}
