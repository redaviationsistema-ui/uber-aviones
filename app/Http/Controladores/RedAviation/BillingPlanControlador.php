<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Plan;
use App\Servicios\Billing\BillingPlanServicio;
use Illuminate\Http\Request;

class BillingPlanControlador extends ControladorBase
{
    public function __construct(private readonly BillingPlanServicio $billingPlanServicio)
    {
    }

    public function index()
    {
        return $this->ok([
            'plans' => $this->billingPlanServicio->activeQuery()
                ->orderByRaw('coalesce(amount, price_monthly, price, 0) asc')
                ->get()
                ->map(fn (Plan $plan) => $this->billingPlanServicio->serialize($plan))
                ->values(),
        ]);
    }

    public function clientAccess()
    {
        $plan = $this->billingPlanServicio->findActiveByCode(BillingPlanServicio::CLIENT_ACCESS_CODE);
        abort_if(! $plan, 404, 'No encontramos el plan de acceso cliente.');

        return $this->ok([
            'plan' => $this->billingPlanServicio->serialize($plan),
        ]);
    }

    public function providerAircraft()
    {
        $plan = $this->billingPlanServicio->findActiveByCode(BillingPlanServicio::PROVIDER_AIRCRAFT_MONTHLY_CODE);
        abort_if(! $plan, 404, 'No encontramos el plan mensual por aeronave.');

        return $this->ok([
            'plan' => $this->billingPlanServicio->serialize($plan),
        ]);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:10'],
            'interval_type' => ['nullable', 'string', 'max:50'],
            'billing_type' => ['nullable', 'string', 'max:60'],
            'user_type' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
            'stripe_product_id' => ['nullable', 'string', 'max:255'],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('amount', $data)) {
            $amount = (float) $data['amount'];
            $data['price'] = $amount;
            if (($data['interval_type'] ?? $plan->interval_type) === 'monthly') {
                $data['price_monthly'] = $amount;
            }
            if (($data['interval_type'] ?? $plan->interval_type) === 'yearly') {
                $data['price_yearly'] = $amount;
            }
        }

        if (array_key_exists('is_active', $data) && ! array_key_exists('status', $data)) {
            $data['status'] = $data['is_active'] ? 'active' : 'inactive';
        }

        $plan->update($data);

        return $this->ok([
            'plan' => $this->billingPlanServicio->serialize($plan->fresh()),
        ]);
    }
}
