<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\DisponibilidadAeronave;
use App\Modelos\DocumentoAeronave;
use App\Modelos\ImagenAeronave;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AeronaveControlador extends ControladorBase
{
    public function index(Request $request)
    {
        $query = Aeronave::with(['provider.user', 'images']);

        if ($request->user()->role === 'provider') {
            $query->where('provider_id', $request->user()->provider?->id);
        }

        return $this->ok(['aircraft' => $query->latest()->paginate(20)]);
    }

    public function store(Request $request)
    {
        $provider = $request->user()->provider;

        if (! $provider || $provider->approval_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'El proveedor debe estar aprobado para registrar aeronaves.',
            ], 403);
        }

        $data = $request->validate($this->rules());
        $aircraft = $provider->aircraft()->create($data);

        return $this->ok(['aircraft' => $aircraft], 201);
    }

    public function show(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);

        return $this->ok(['aircraft' => $aircraft->load(['provider.user', 'images', 'availability'])]);
    }

    public function update(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);

        $aircraft->update($request->validate($this->rules(false)));

        return $this->ok(['aircraft' => $aircraft->fresh()]);
    }

    public function destroy(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);
        $aircraft->delete();

        return $this->ok(['message' => 'Aeronave eliminada.']);
    }

    public function storeAvailability(Request $request)
    {
        $data = $request->validate([
            'aircraft_id' => ['required', 'exists:aircraft,id'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'status' => ['required', 'in:available,occupied,blocked,maintenance'],
            'notes' => ['nullable', 'string'],
        ]);

        $aircraft = Aeronave::findOrFail($data['aircraft_id']);
        $this->authorizeProveedorAeronave($request, $aircraft);

        return $this->ok([
            'availability' => DisponibilidadAeronave::create($data),
        ], 201);
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'origin' => ['required', 'string', 'max:20'],
            'departure_datetime' => ['required', 'date'],
            'passengers' => ['required', 'integer', 'min:1'],
        ]);

        $start = Carbon::parse($data['departure_datetime']);
        $end = $start->copy()->addHours(4);

        $aircraft = Aeronave::with(['provider.user', 'images'])
            ->where('status', 'active')
            ->where('capacity', '>=', $data['passengers'])
            ->where('base_airport', $data['origin'])
            ->whereHas('provider', fn ($query) => $query->where('approval_status', 'approved'))
            ->whereDoesntHave('availability', function ($query) use ($start, $end) {
                $query->whereIn('status', ['occupied', 'blocked', 'maintenance'])
                    ->where('start_datetime', '<', $end)
                    ->where('end_datetime', '>', $start);
            })
            ->orderBy('hourly_rate')
            ->get();

        return $this->ok(['aircraft' => $aircraft]);
    }

    public function preview()
    {
        return $this->ok([
            'aircraft' => Aeronave::with(['provider', 'images'])
                ->where('status', 'active')
                ->latest()
                ->limit(12)
                ->get(),
        ]);
    }

    public function storeImage(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);

        $data = $request->validate([
            'image_url' => ['required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_main' => ['sometimes', 'boolean'],
        ]);

        return $this->ok(['image' => $aircraft->images()->create($data)], 201);
    }

    public function destroyImage(Request $request, Aeronave $aircraft, ImagenAeronave $image)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);
        abort_if($image->aircraft_id !== $aircraft->id, 404);
        $image->delete();

        return $this->ok(['message' => 'Imagen eliminada.']);
    }

    public function storeDocument(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);

        $data = $request->validate([
            'type' => ['required_without:document_type', 'nullable', 'string', 'max:100'],
            'file_url' => ['required_without:document_url', 'nullable', 'string', 'max:255'],
            'document_type' => ['required_without:type', 'nullable', 'string', 'max:100'],
            'document_name' => ['nullable', 'string', 'max:150'],
            'document_url' => ['required_without:file_url', 'nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $data['type'] = $data['type'] ?? $data['document_type'];
        $data['file_url'] = $data['file_url'] ?? $data['document_url'];
        $data['document_type'] = $data['document_type'] ?? $data['type'];
        $data['document_url'] = $data['document_url'] ?? $data['file_url'];

        return $this->ok(['document' => $aircraft->documents()->create($data)], 201);
    }

    public function destroyDocument(Request $request, Aeronave $aircraft, DocumentoAeronave $document)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);
        abort_if($document->aircraft_id !== $aircraft->id, 404);
        $document->delete();

        return $this->ok(['message' => 'Documento eliminado.']);
    }

    public function availability(Request $request)
    {
        $query = DisponibilidadAeronave::with('aircraft');

        if ($request->user()->role === 'provider') {
            $query->whereHas('aircraft', fn ($scope) => $scope->where('provider_id', $request->user()->provider?->id));
        }

        return $this->ok(['availability' => $query->latest()->paginate(30)]);
    }

    public function updateAvailability(Request $request, DisponibilidadAeronave $availability)
    {
        $this->authorizeProveedorAeronave($request, $availability->aircraft);

        $availability->update($request->validate([
            'start_datetime' => ['sometimes', 'date'],
            'end_datetime' => ['sometimes', 'date', 'after:start_datetime'],
            'status' => ['sometimes', 'in:available,occupied,blocked,maintenance'],
            'notes' => ['nullable', 'string'],
        ]));

        return $this->ok(['availability' => $availability->fresh()]);
    }

    public function destroyAvailability(Request $request, DisponibilidadAeronave $availability)
    {
        $this->authorizeProveedorAeronave($request, $availability->aircraft);
        $availability->delete();

        return $this->ok(['message' => 'Disponibilidad eliminada.']);
    }

    private function rules(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'model' => [$required, 'string', 'max:255'],
            'registration' => [$required, 'string', 'max:50'],
            'capacity' => [$required, 'integer', 'min:1'],
            'base_airport' => [$required, 'string', 'max:20'],
            'range_km' => ['nullable', 'integer', 'min:0'],
            'speed_kmh' => ['nullable', 'integer', 'min:0'],
            'hourly_rate' => [$required, 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'in:active,inactive,maintenance,blocked'],
        ];
    }

    private function authorizeProveedorAeronave(Request $request, Aeronave $aircraft): void
    {
        if ($request->user()->role === 'admin') {
            return;
        }

        abort_if($aircraft->provider_id !== $request->user()->provider?->id, 403, 'No puedes gestionar esta aeronave.');
    }
}
