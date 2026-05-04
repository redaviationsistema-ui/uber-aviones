<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\SolicitudVuelo;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SolicitudVueloControlador extends ControladorBase
{
    public function index(Request $request)
    {
        $query = SolicitudVuelo::with(['matches.aircraft', 'quotes'])
            ->latest();

        if ($request->user()->hasRole('client') && ! $request->user()->hasRole('admin')) {
            $query->where('client_id', $request->user()->id);
        }

        return $this->ok(['flight_requests' => $query->paginate(20)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'origin' => ['required', 'string', 'max:20'],
            'destination' => ['required', 'string', 'max:20'],
            'departure_datetime' => ['required_without:departure_date', 'date'],
            'departure_date' => ['required_without:departure_datetime', 'date'],
            'departure_time' => ['required_with:departure_date', 'date_format:H:i'],
            'return_datetime' => ['nullable', 'date', 'after:departure_datetime'],
            'return_date' => ['nullable', 'date'],
            'return_time' => ['nullable', 'date_format:H:i'],
            'passengers' => ['required', 'integer', 'min:1'],
            'trip_type' => ['required', 'in:one_way,round_trip,multi_leg'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! isset($data['departure_datetime'])) {
            $data['departure_datetime'] = Carbon::parse($data['departure_date'].' '.$data['departure_time']);
        } else {
            $departure = Carbon::parse($data['departure_datetime']);
            $data['departure_date'] = $data['departure_date'] ?? $departure->format('Y-m-d');
            $data['departure_time'] = $data['departure_time'] ?? $departure->format('H:i');
        }

        if (! isset($data['return_datetime']) && isset($data['return_date'], $data['return_time'])) {
            $data['return_datetime'] = Carbon::parse($data['return_date'].' '.$data['return_time']);
        }

        $flightRequest = SolicitudVuelo::create($data + [
            'client_id' => $request->user()->id,
            'status' => 'pending',
        ]);

        $this->matchAeronave($flightRequest);
        $this->writeAudit($request, 'create', 'flight_requests', 'Solicitud de vuelo creada.');

        return $this->ok(['flight_request' => $flightRequest->load('matches.aircraft.provider')], 201);
    }

    public function show(Request $request, SolicitudVuelo $flightRequest)
    {
        if ($request->user()->hasRole('client') && ! $request->user()->hasRole('admin') && $flightRequest->client_id !== $request->user()->id) {
            abort(403, 'No puedes ver esta solicitud.');
        }

        return $this->ok(['flight_request' => $flightRequest->load(['matches.aircraft', 'quotes.aircraft'])]);
    }

    public function history(Request $request)
    {
        return $this->ok([
            'flight_requests' => SolicitudVuelo::with(['quotes', 'matches.aircraft'])
                ->where('client_id', $request->user()->id)
                ->latest()
                ->limit(50)
                ->get(),
            'reservations' => $request->user()->reservations()->with(['quote', 'aircraft', 'contract', 'review', 'payments'])->latest()->limit(50)->get(),
            'payments' => $request->user()->reservations()
                ->with('payments')
                ->latest()
                ->limit(50)
                ->get()
                ->pluck('payments')
                ->flatten()
                ->values(),
        ]);
    }

    private function matchAeronave(SolicitudVuelo $flightRequest): void
    {
        $start = $flightRequest->departure_datetime
            ? Carbon::parse($flightRequest->departure_datetime)
            : Carbon::parse($flightRequest->departure_date->format('Y-m-d').' '.$flightRequest->departure_time);
        $end = $start->copy()->addHours(4);

        $aircraft = Aeronave::with('provider')
            ->where('status', 'active')
            ->where('capacity', '>=', $flightRequest->passengers)
            ->where('base_airport', $flightRequest->origin)
            ->whereHas('provider', fn ($query) => $query->where('approval_status', 'approved'))
            ->whereDoesntHave('availability', function ($query) use ($start, $end) {
                $query->whereIn('status', ['occupied', 'blocked', 'maintenance'])
                    ->where('start_datetime', '<', $end)
                    ->where('end_datetime', '>', $start);
            })
            ->limit(10)
            ->get();

        foreach ($aircraft as $item) {
            $score = max(1, 100 - abs($item->capacity - $flightRequest->passengers) * 5);
            $flightRequest->matches()->create([
                'aircraft_id' => $item->id,
                'provider_id' => $item->provider_id,
                'match_score' => $score,
                'status' => 'pending',
            ]);
        }

        if ($aircraft->isNotEmpty()) {
            $flightRequest->update(['status' => 'matched']);
        }
    }
}
