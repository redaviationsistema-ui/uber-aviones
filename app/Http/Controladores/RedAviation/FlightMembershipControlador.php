<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Cotizacion;
use App\Modelos\FlightMembership;
use App\Modelos\FlightMembershipBenefitLedger;
use App\Modelos\FlightMembershipPlan;
use App\Servicios\Billing\FlightMembershipService;
use Illuminate\Http\Request;

class FlightMembershipControlador extends ControladorBase
{
    public function __construct(private readonly FlightMembershipService $flightMembershipService)
    {
    }

    public function plans()
    {
        $plans = $this->flightMembershipService->activePlansQuery()->orderBy('price')->get();

        return $this->ok([
            'plans' => $plans->map(fn (FlightMembershipPlan $plan) => $this->flightMembershipService->serializePlan($plan))->all(),
        ]);
    }

    public function checkout(Request $request)
    {
        if (! config('services.stripe.secret')) {
            abort(422, 'Stripe no esta configurado para membresias de vuelo.');
        }

        $data = $request->validate([
            'plan_id' => ['required', 'exists:flight_membership_plans,id'],
            'success_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
            'contact_email' => ['nullable', 'email:rfc,dns'],
        ]);

        $plan = FlightMembershipPlan::query()->where('is_active', true)->findOrFail((int) $data['plan_id']);
        $result = $this->flightMembershipService->createCheckout($request->user(), $plan, $data);

        return $this->ok($result, isset($result['checkout_session_id']) ? 201 : 200);
    }

    public function current(Request $request)
    {
        $membership = $this->flightMembershipService->findCurrentMembershipForUser((int) $request->user()->id);

        return $this->ok([
            'membership' => $membership ? $this->flightMembershipService->serializeMembership($membership) : null,
        ]);
    }

    public function history(Request $request)
    {
        $memberships = FlightMembership::query()
            ->with(['plan', 'currentPeriod'])
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->get();

        $ledger = FlightMembershipBenefitLedger::query()
            ->whereHas('membership', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest('occurred_at')
            ->paginate(min(max((int) $request->integer('per_page', 50), 1), 100));

        return $this->ok([
            'memberships' => $memberships->map(fn (FlightMembership $membership) => $this->flightMembershipService->serializeMembership($membership))->all(),
            'ledger' => collect($ledger->items())->map(fn (FlightMembershipBenefitLedger $entry) => $this->flightMembershipService->serializeLedgerEntry($entry))->all(),
            'pagination' => [
                'current_page' => $ledger->currentPage(),
                'last_page' => $ledger->lastPage(),
                'per_page' => $ledger->perPage(),
                'total' => $ledger->total(),
            ],
        ]);
    }

    public function quotePreview(Request $request, Cotizacion $quote)
    {
        abort_if($quote->flightRequest?->client_id !== $request->user()->id && ! $request->user()->hasRole('admin'), 403);

        return $this->ok([
            'preview' => $this->flightMembershipService->previewForQuote($request->user(), $quote->load(['flightRequest', 'aircraft'])),
        ]);
    }
}
