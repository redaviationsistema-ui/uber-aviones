<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\DisponibilidadAeronave;
use App\Modelos\DocumentoAeronave;
use App\Modelos\ImagenAeronave;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AeronaveControlador extends ControladorBase
{
    public function index(Request $request)
    {
        $query = Aeronave::with(['provider.user.activeSuscripcion.plan', 'images', 'availability', 'documents']);

        if ($request->user()->hasRole('provider') && ! $request->user()->hasRole('admin')) {
            $query->where('provider_id', $request->user()->provider_id);
        }

        $aircraft = $query->latest()->paginate(20);
        $aircraft->setCollection(
            $aircraft->getCollection()->map(fn (Aeronave $item) => $this->formatAircraftPayload($item))
        );

        return $this->ok(['aircraft' => $aircraft]);
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

        return $this->ok(['aircraft' => $this->formatAircraftPayload($aircraft->fresh(['provider.user.activeSuscripcion.plan', 'availability', 'documents', 'images']))], 201);
    }

    public function show(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);

        return $this->ok([
            'aircraft' => $this->formatAircraftPayload(
                $aircraft->load(['provider.user.activeSuscripcion.plan', 'images', 'availability', 'documents'])
            ),
        ]);
    }

    public function update(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);

        $aircraft->update($request->validate($this->rules(false)));

        return $this->ok([
            'aircraft' => $this->formatAircraftPayload(
                $aircraft->fresh(['provider.user.activeSuscripcion.plan', 'images', 'availability', 'documents'])
            ),
        ]);
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
            ->whereIn('status', ['active', 'trial_active'])
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

        return $this->ok([
            'aircraft' => $aircraft->map(fn (Aeronave $item) => $this->formatPublicAircraftPayload($item))->values(),
        ]);
    }

    public function preview()
    {
        return $this->ok([
            'aircraft' => Aeronave::with(['provider', 'images'])
                ->whereIn('status', ['active', 'trial_active'])
                ->latest()
                ->limit(12)
                ->get()
                ->map(fn (Aeronave $item) => $this->formatPublicAircraftPayload($item))
                ->values(),
        ]);
    }

    public function storeImage(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);

        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'max:10240'],
            'kind' => ['sometimes', 'string', 'in:main,exterior,interior,cabin,seats,amenities,gallery'],
            'title' => ['nullable', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_main' => ['sometimes', 'boolean'],
            'visible_to_client' => ['sometimes', 'boolean'],
        ]);

        $path = $request->file('image')->store('aircraft', 's3');
        $imageUrl = Storage::disk('s3')->url($path);
        $isMain = (bool) ($data['is_main'] ?? false);

        if ($isMain) {
            $aircraft->images()->update(['is_main' => false]);
        }

        $image = $aircraft->images()->create([
            'kind' => $data['kind'] ?? ($isMain ? 'main' : 'gallery'),
            'title' => $data['title'] ?? null,
            'image_url' => $imageUrl,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_main' => $isMain,
            'visible_to_client' => array_key_exists('visible_to_client', $data) ? (bool) $data['visible_to_client'] : true,
        ]);

        return $this->ok([
            'image' => $image,
            'path' => $path,
            'url' => $imageUrl,
        ], 201);
    }

    public function destroyImage(Request $request, Aeronave $aircraft, ImagenAeronave $image)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);
        abort_if($image->aircraft_id !== $aircraft->id, 404);

        $path = $this->resolveS3Path($image->image_url);

        if ($path) {
            Storage::disk('s3')->delete($path);
        }

        $image->delete();

        return $this->ok(['message' => 'Imagen eliminada.']);
    }

    public function storeDocument(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);

        $data = $request->validate([
            'type' => ['required_without:document_type', 'nullable', 'string', 'max:100'],
            'file' => ['required_without_all:file_url,document_url', 'nullable', 'file', 'max:20480'],
            'file_url' => ['required_without_all:file,document_url', 'nullable', 'string', 'max:255'],
            'document_type' => ['required_without:type', 'nullable', 'string', 'max:100'],
            'document_name' => ['nullable', 'string', 'max:150'],
            'document_url' => ['required_without_all:file,file_url', 'nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('aircraft-documents', 's3');
            $documentUrl = Storage::disk('s3')->url($path);
            $data['file_url'] = $documentUrl;
            $data['document_url'] = $documentUrl;
            $data['document_name'] = $data['document_name'] ?? $request->file('file')->getClientOriginalName();
        }

        $data['type'] = $data['type'] ?? $data['document_type'];
        $data['file_url'] = $data['file_url'] ?? $data['document_url'];
        $data['document_type'] = $data['document_type'] ?? $data['type'];
        $data['document_url'] = $data['document_url'] ?? $data['file_url'];

        $document = $aircraft->documents()->create($data);

        return $this->ok([
            'document' => $document,
            'url' => $document->document_url,
        ], 201);
    }

    public function destroyDocument(Request $request, Aeronave $aircraft, DocumentoAeronave $document)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);
        abort_if($document->aircraft_id !== $aircraft->id, 404);

        $path = $this->resolveS3Path($document->document_url ?: $document->file_url);
        if ($path) {
            Storage::disk('s3')->delete($path);
        }

        $document->delete();

        return $this->ok(['message' => 'Documento eliminado.']);
    }

    public function availability(Request $request)
    {
        $query = DisponibilidadAeronave::with('aircraft');

        if ($request->user()->hasRole('provider') && ! $request->user()->hasRole('admin')) {
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
            'security_filter' => ['nullable', 'string', 'max:50'],
            'security_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'airworthiness_status' => ['nullable', 'string', 'max:100'],
            'last_maintenance_at' => ['nullable', 'date'],
            'engine_run_at' => ['nullable', 'date'],
            'captain_training_at' => ['nullable', 'date'],
            'lodging_location' => ['nullable', 'string', 'max:150'],
            'client_fbo' => ['nullable', 'string', 'max:120'],
            'dispatch_center' => ['nullable', 'string', 'max:120'],
            'dispatch_notes' => ['nullable', 'string'],
            'security_notes' => ['nullable', 'string'],
        ];
    }

    private function formatAircraftPayload(Aeronave $aircraft): array
    {
        $providerUser = $aircraft->provider?->user;
        $plan = $providerUser?->activeSuscripcion?->plan;
        $providerAircraftCount = $aircraft->provider?->aircraft()->count() ?? 1;
        $monthlyBase = (float) ($plan?->price_monthly ?? $plan?->price_yearly ?? $plan?->price ?? 0);
        $monthlyPerAircraft = $providerAircraftCount > 0 && $monthlyBase > 0
            ? round($monthlyBase / $providerAircraftCount, 2)
            : null;

        return [
            ...$aircraft->toArray(),
            'main_image' => $this->resolveMainImageUrl($aircraft),
            'membership_context' => $plan ? [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_cycle' => $plan->billing_cycle,
                'max_aircraft' => $plan->max_aircraft,
                'monthly_cost_per_aircraft' => $monthlyPerAircraft,
                'within_plan_limit' => $plan->max_aircraft ? $providerAircraftCount <= $plan->max_aircraft : true,
            ] : null,
        ];
    }

    private function authorizeProveedorAeronave(Request $request, Aeronave $aircraft): void
    {
        if ($request->user()->hasRole('admin')) {
            return;
        }

        abort_if($aircraft->provider_id !== $request->user()->provider_id, 403, 'No puedes gestionar esta aeronave.');
    }

    private function formatPublicAircraftPayload(Aeronave $aircraft): array
    {
        $visibleImages = $aircraft->images
            ->where('visible_to_client', true)
            ->sortBy([
                ['is_main', 'desc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $mainImage = $visibleImages->firstWhere('is_main', true)?->image_url
            ?? $visibleImages->first()?->image_url;

        return [
            'id' => $aircraft->id,
            'model' => $aircraft->model,
            'category' => $aircraft->category ?? 'Cabina ejecutiva',
            'capacity' => $aircraft->capacity,
            'range_km' => $aircraft->range_km,
            'status' => $aircraft->status,
            'main_image' => $mainImage,
            'images' => $visibleImages->map(fn (ImagenAeronave $image) => [
                'id' => $image->id,
                'kind' => $image->kind,
                'title' => $image->title,
                'image_url' => $image->image_url,
                'is_main' => $image->is_main,
            ])->values(),
            'amenities' => $visibleImages
                ->whereIn('kind', ['amenities', 'cabin', 'seats'])
                ->pluck('title')
                ->filter()
                ->values(),
        ];
    }

    private function resolveMainImageUrl(Aeronave $aircraft): ?string
    {
        $images = $aircraft->images
            ->sortBy([
                ['is_main', 'desc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        return $images->firstWhere('is_main', true)?->image_url ?? $images->first()?->image_url;
    }

    private function resolveS3Path(string $url): ?string
    {
        $configuredUrl = rtrim((string) config('filesystems.disks.s3.url'), '/');

        if ($configuredUrl !== '' && str_starts_with($url, $configuredUrl.'/')) {
            return ltrim(substr($url, strlen($configuredUrl)), '/');
        }

        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return null;
        }

        $bucket = (string) config('filesystems.disks.s3.bucket');

        if ($bucket !== '' && str_starts_with($path, $bucket.'/')) {
            return ltrim(substr($path, strlen($bucket)), '/');
        }

        return $path;
    }
}
