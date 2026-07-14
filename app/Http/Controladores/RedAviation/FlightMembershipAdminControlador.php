<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\FlightMembership;
use App\Modelos\FlightMembershipBenefitLedger;
use App\Modelos\FlightMembershipPlan;
use App\Servicios\Billing\FlightMembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FlightMembershipAdminControlador extends ControladorBase
{
    public function __construct(private readonly FlightMembershipService $flightMembershipService)
    {
    }

    public function plans(Request $request)
    {
        $plans = FlightMembershipPlan::query()->latest('id')->paginate(min(max((int) $request->integer('per_page', 20), 1), 100));

        return $this->ok([
            'plans' => collect($plans->items())->map(fn (FlightMembershipPlan $plan) => $this->flightMembershipService->serializePlan($plan))->all(),
            'pagination' => [
                'current_page' => $plans->currentPage(),
                'last_page' => $plans->lastPage(),
                'per_page' => $plans->perPage(),
                'total' => $plans->total(),
            ],
        ]);
    }

    public function storePlan(Request $request)
    {
        $data = $this->validatePlan($request);
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        $plan = FlightMembershipPlan::create($data);
        $this->writeAudit($request, 'create', 'flight_membership_plans', 'Plan de membresia de vuelo creado.');

        return $this->ok([
            'plan' => $this->flightMembershipService->serializePlan($plan),
        ], 201);
    }

    public function showPlan(FlightMembershipPlan $plan)
    {
        return $this->ok([
            'plan' => $this->flightMembershipService->serializePlan($plan),
        ]);
    }

    public function updatePlan(Request $request, FlightMembershipPlan $plan)
    {
        $data = $this->validatePlan($request, true);
        if (array_key_exists('name', $data) && ! array_key_exists('slug', $data)) {
            $data['slug'] = Str::slug((string) $data['name']);
        }

        $plan->update($data);
        $this->writeAudit($request, 'update', 'flight_membership_plans', 'Plan de membresia de vuelo actualizado.');

        return $this->ok([
            'plan' => $this->flightMembershipService->serializePlan($plan->fresh()),
        ]);
    }

    public function updatePlanStatus(Request $request, FlightMembershipPlan $plan)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $plan->update(['is_active' => (bool) $data['is_active']]);
        $this->writeAudit($request, 'update_status', 'flight_membership_plans', 'Estado de plan de membresia actualizado.');

        return $this->ok([
            'plan' => $this->flightMembershipService->serializePlan($plan->fresh()),
        ]);
    }

    public function memberships(Request $request)
    {
        $memberships = FlightMembership::query()
            ->with(['user:id,name,email', 'plan', 'currentPeriod'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 100));

        return $this->ok([
            'memberships' => collect($memberships->items())->map(function (FlightMembership $membership) {
                return $this->flightMembershipService->serializeMembership($membership) + [
                    'user' => [
                        'id' => $membership->user?->id,
                        'name' => $membership->user?->name,
                        'email' => $membership->user?->email,
                    ],
                ];
            })->all(),
            'pagination' => [
                'current_page' => $memberships->currentPage(),
                'last_page' => $memberships->lastPage(),
                'per_page' => $memberships->perPage(),
                'total' => $memberships->total(),
            ],
        ]);
    }

    public function showMembership(FlightMembership $membership)
    {
        $membership->load(['user:id,name,email', 'plan', 'currentPeriod']);
        $ledger = FlightMembershipBenefitLedger::query()
            ->where('flight_membership_id', $membership->id)
            ->latest('occurred_at')
            ->limit(100)
            ->get();

        return $this->ok([
            'membership' => $this->flightMembershipService->serializeMembership($membership),
            'user' => [
                'id' => $membership->user?->id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
            ],
            'ledger' => $ledger->map(fn (FlightMembershipBenefitLedger $entry) => $this->flightMembershipService->serializeLedgerEntry($entry))->all(),
        ]);
    }

    public function adjustment(Request $request, FlightMembership $membership)
    {
        $data = $request->validate([
            'benefit_type' => ['required', 'in:flight,hour,credit,discount'],
            'quantity' => ['nullable', 'numeric'],
            'amount' => ['nullable', 'numeric'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $entry = $this->flightMembershipService->createManualAdjustment($membership, $data + [
            'actor_user_id' => $request->user()?->id,
        ]);
        $this->writeAudit($request, 'adjust', 'flight_membership_ledger', 'Ajuste manual de membresia de vuelo.', [
            'new_values' => $this->flightMembershipService->serializeLedgerEntry($entry),
        ]);

        return $this->ok([
            'entry' => $this->flightMembershipService->serializeLedgerEntry($entry),
            'membership' => $this->flightMembershipService->serializeMembership($membership->fresh(['plan', 'currentPeriod'])),
        ], 201);
    }

    private function validatePlan(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return $request->validate([
            'name' => [...$required, 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'price' => [...$required, 'numeric', 'min:0'],
            'currency' => [...$required, 'string', 'max:10'],
            'billing_interval' => [...$required, 'in:monthly,yearly'],
            'included_flights' => ['nullable', 'numeric', 'min:0'],
            'included_hours' => ['nullable', 'numeric', 'min:0'],
            'included_credit_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rollover_flights' => ['nullable', 'boolean'],
            'rollover_hours' => ['nullable', 'boolean'],
            'rollover_credits' => ['nullable', 'boolean'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'auto_renew' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'stripe_product_id' => ['nullable', 'string', 'max:255'],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
