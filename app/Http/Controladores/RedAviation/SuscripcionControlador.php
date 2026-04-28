<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Demo;
use App\Modelos\Plan;
use App\Modelos\Suscripcion;
use Illuminate\Http\Request;

class SuscripcionControlador extends ControladorBase
{
    public function startTrial(Request $request)
    {
        $demo = Demo::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'started_at' => now(),
                'expires_at' => now()->addDays(15),
                'status' => 'active',
            ]
        );

        return $this->ok(['demo' => $demo, 'subscription_status' => 'demo_activa'], 201);
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'payment_provider' => ['nullable', 'string', 'max:100'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);
        $suscripcion = Suscripcion::create([
            'user_id' => $request->user()->id,
            'plan_id' => $plan->id,
            'started_at' => now(),
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'ends_at' => now()->addMonth(),
            'renews_at' => now()->addMonth(),
            'status' => 'active',
            'payment_status' => 'paid',
            'payment_provider' => $data['payment_provider'] ?? 'manual',
            'provider_subscription_id' => 'RA-'.strtoupper((string) str()->random(10)),
        ]);

        return $this->ok(['subscription' => $suscripcion->load('plan')], 201);
    }

    public function current(Request $request)
    {
        return $this->ok([
            'subscription' => $request->user()->activeSuscripcion?->load('plan'),
            'access' => $request->user()->accessStatus(),
        ]);
    }

    public function cancel(Request $request)
    {
        $suscripcion = $request->user()->activeSuscripcion;
        abort_if(! $suscripcion, 404, 'No existe una suscripcion activa.');

        $suscripcion->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return $this->ok(['subscription' => $suscripcion->fresh('plan')]);
    }
}
