<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Modelos\DisponibilidadAeronave;
use App\Modelos\DocumentoAeronave;
use App\Modelos\ImagenAeronave;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AeronaveControlador extends ControladorBase
{
    private const AIRCRAFT_CATEGORIES = [
        'Helicoptero',
        'Turboprop',
        'Light Jet',
        'Mid Jet',
        'Heavy Jet',
    ];

    private const CATEGORY_CLIMB_DESCENT_MINUTES = [
        'Helicoptero' => 25,
        'Turboprop' => 25,
        'Light Jet' => 30,
        'Mid Jet' => 35,
        'Heavy Jet' => 45,
    ];

    public function index(Request $request)
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $query = Aeronave::query()
            ->select([
                'id',
                'provider_id',
                'model',
                'manufacturer',
                'category',
                'model_year',
                'registration',
                'capacity',
                'base_airport',
                'base_airport_id',
                'range_km',
                'speed_kmh',
                'coverage',
                'amenities',
                'hourly_rate',
                'airport_expenses_usd',
                'minimum_hours',
                'minimum_route_price',
                'climb_descent_minutes',
                'operational_cost',
                'fuel_burn_gph',
                'engine_reserve_rate',
                'insurance_rate',
                'maintenance_rate',
                'crew_rate',
                'repositioning_fee',
                'overnight_fee',
                'currency',
                'status',
                'billing_status',
                'billing_plan_id',
                'subscription_status',
                'subscription_started_at',
                'subscription_ends_at',
                'last_payment_at',
                'security_filter',
                'security_score',
                'airworthiness_status',
                'last_maintenance_at',
                'engine_run_at',
                'captain_training_at',
                'lodging_location',
                'client_fbo',
                'dispatch_center',
                'dispatch_notes',
                'security_notes',
                'created_at',
                'updated_at',
            ])
            ->with([
                'provider' => fn ($query) => $query
                    ->select(['id', 'user_id', 'company_name', 'commercial_name'])
                    ->withCount('aircraft'),
                'baseAirport:id,icao,iata',
                'images:id,aircraft_id,kind,title,image_url,is_main,visible_to_client,sort_order',
                'availability:id,aircraft_id,start_datetime,end_datetime,status,notes',
                'documents:id,aircraft_id,type,file_url,document_type,document_name,document_url,storage_disk,storage_path,thumbnail_path,thumbnail_url,expires_at,created_at,updated_at',
            ]);
        $providerAircraftCount = null;
        $providerPlan = null;

        if ($request->user()->hasRole('provider') && ! $request->user()->hasRole('admin')) {
            $query->where('provider_id', $request->user()->resolvedProviderId());
            $providerAircraftCount = $query->toBase()->getCountForPagination();
            $providerPlan = $request->user()->loadMissing('activeSuscripcion.plan')->activeSuscripcion?->plan;
        }

        $aircraft = $query->latest()->paginate($perPage);
        $aircraft->setCollection(
            $aircraft->getCollection()->map(
                fn (Aeronave $item) => $this->formatAircraftPayload($item, $providerPlan, $providerAircraftCount)
            )
        );

        return $this->ok(['aircraft' => $aircraft]);
    }

    public function store(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 422, 'El usuario proveedor no tiene provider_id asignado.');
        abort_if($provider->approval_status !== 'approved', 403, 'Proveedor pendiente de validacion por Admin.');

        $data = $this->normalizeAircraftInput($request->validate($this->rules()));
        $aircraft = $provider->aircraft()->create($data + [
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'inactive',
            'currency' => $data['currency'] ?? 'USD',
        ]);

        return $this->ok([
            'aircraft' => $this->formatAircraftPayload(
                $aircraft->fresh(['provider.user.activeSuscripcion.plan', 'availability', 'documents', 'images'])
            ),
            'message' => 'Aeronave registrada correctamente. Pendiente de activacion.',
            'redirect_to' => '/provider/aircraft/'.$aircraft->id.'/billing',
        ], 201);
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

        $aircraft->update($this->normalizeAircraftInput($request->validate($this->rules(false, $aircraft)), $aircraft));

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
        $originAirport = $this->findAirportByCode($data['origin']);

        $aircraft = Aeronave::with(['provider.user', 'images', 'baseAirport:id,icao,iata'])
            ->whereIn('status', ['active', 'trial_active'])
            ->where('capacity', '>=', $data['passengers'])
            ->where(function ($query) use ($data, $originAirport) {
                $query->where('base_airport', $data['origin']);

                if ($originAirport) {
                    $query->orWhere('base_airport_id', $originAirport->id);
                }
            })
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

        $file = $request->file('image');
        $disk = $this->resolveUploadDisk();
        $directory = sprintf('provider/%s/aircraft/%s/images', $aircraft->provider_id, $aircraft->id);
        [$disk, $path] = $this->storeUploadedFileWithFallback(
            $file,
            $directory,
            $file->hashName(),
            $disk,
            'No se pudo subir la imagen al almacenamiento.'
        );
        $imageUrl = $this->resolveUploadedFileUrl($request, $disk, $path);
        $isMain = (bool) ($data['is_main'] ?? false);

        try {
            $image = DB::transaction(function () use ($aircraft, $data, $imageUrl, $isMain) {
                if ($isMain) {
                    $aircraft->images()->update(['is_main' => false]);
                }

                return $aircraft->images()->create([
                    'kind' => $data['kind'] ?? ($isMain ? 'main' : 'gallery'),
                    'title' => $data['title'] ?? null,
                    'image_url' => $imageUrl,
                    'sort_order' => $data['sort_order'] ?? 0,
                    'is_main' => $isMain,
                    'visible_to_client' => array_key_exists('visible_to_client', $data) ? (bool) $data['visible_to_client'] : true,
                ]);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }

        return $this->ok([
            'image' => $image,
            'images' => $aircraft->fresh('images')->images,
            'path' => $path,
            'url' => $imageUrl,
        ], 201);
    }

    public function images(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);

        return $this->ok([
            'images' => $aircraft->images()
                ->orderByDesc('is_main')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function attachExistingImage(Request $request, Aeronave $aircraft)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);

        $data = $request->validate([
            'image_url' => ['required', 'string', 'max:2048'],
            'kind' => ['sometimes', 'string', 'in:main,exterior,interior,cabin,seats,amenities,gallery'],
            'title' => ['nullable', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_main' => ['sometimes', 'boolean'],
            'visible_to_client' => ['sometimes', 'boolean'],
        ]);

        $imageUrl = trim((string) $data['image_url']);
        $isMain = (bool) ($data['is_main'] ?? false);
        $kind = $data['kind'] ?? ($isMain ? 'main' : 'gallery');

        $image = DB::transaction(function () use ($aircraft, $data, $imageUrl, $isMain, $kind) {
            if ($isMain) {
                $aircraft->images()->update(['is_main' => false]);
            }

            $existing = $aircraft->images()
                ->where('image_url', $imageUrl)
                ->first();

            if ($existing) {
                $existing->update([
                    'kind' => $kind,
                    'title' => $data['title'] ?? $existing->title,
                    'sort_order' => $data['sort_order'] ?? $existing->sort_order ?? 0,
                    'is_main' => $isMain,
                    'visible_to_client' => array_key_exists('visible_to_client', $data)
                        ? (bool) $data['visible_to_client']
                        : $existing->visible_to_client,
                ]);

                return $existing->fresh();
            }

            return $aircraft->images()->create([
                'kind' => $kind,
                'title' => $data['title'] ?? null,
                'image_url' => $imageUrl,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_main' => $isMain,
                'visible_to_client' => array_key_exists('visible_to_client', $data) ? (bool) $data['visible_to_client'] : true,
            ]);
        });

        return $this->ok([
            'image' => $image,
            'aircraft' => $this->formatAircraftPayload($aircraft->fresh([
                'provider.user.activeSuscripcion.plan',
                'images',
                'availability',
                'documents',
            ])),
        ]);
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
            'document_type' => ['required_without:type', 'nullable', 'string', 'max:100'],
            'file' => ['required_without_all:documents,file_url,document_url', 'nullable', 'file', 'max:25600'],
            'documents' => ['required_without_all:file,file_url,document_url', 'nullable', 'array', 'max:20'],
            'documents.*' => ['required', 'file', 'max:25600'],
            'file_url' => ['required_without_all:file,documents,document_url', 'nullable', 'string', 'max:255'],
            'document_url' => ['required_without_all:file,documents,file_url', 'nullable', 'string'],
            'document_name' => ['nullable', 'string', 'max:150'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $documentType = $data['document_type'] ?? $data['type'];
        $uploadedFiles = $this->resolveUniqueDocumentUploads($request);

        $createdDocuments = [];

        foreach ($uploadedFiles as $file) {
            $createdDocuments[] = $this->storeAircraftDocumentFile(
                $aircraft,
                $file,
                $documentType,
                $data['expires_at'] ?? null,
                $data['document_name'] ?? null,
                $data['notes'] ?? null,
            );
        }

        if (! empty($createdDocuments)) {
            return $this->ok([
                'documents' => $createdDocuments,
                'document' => $createdDocuments[0],
                'uploaded' => count($createdDocuments),
                'url' => $createdDocuments[0]->document_url,
            ], 201);
        }

        $documentUrl = $data['file_url'] ?? $data['document_url'];
        $document = $aircraft->documents()->create([
            'provider_id' => $aircraft->provider_id,
            'type' => $documentType,
            'document_type' => $documentType,
            'document_name' => $data['document_name'] ?? basename((string) parse_url($documentUrl, PHP_URL_PATH)),
            'file_url' => $documentUrl,
            'document_url' => $documentUrl,
            'file_type' => $this->guessFileTypeFromUrl($documentUrl),
            'expires_at' => $data['expires_at'] ?? null,
            'status' => 'pending',
            'verified_by_admin' => false,
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->ok([
            'document' => $document,
            'uploaded' => 1,
            'url' => $document->document_url,
        ], 201);
    }

    public function destroyDocument(Request $request, Aeronave $aircraft, DocumentoAeronave $document)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);
        abort_if($document->aircraft_id !== $aircraft->id, 404);

        $paths = array_filter([
            $document->storage_path,
            $document->thumbnail_path,
            $this->resolveS3Path($document->document_url ?: $document->file_url),
            $this->resolveS3Path($document->thumbnail_url ?: ''),
        ]);

        if ($paths) {
            Storage::disk('s3')->delete(array_values(array_unique($paths)));
        }

        $document->delete();

        return $this->ok(['message' => 'Documento eliminado.']);
    }

    public function downloadDocument(Request $request, Aeronave $aircraft, DocumentoAeronave $document)
    {
        $this->authorizeProveedorAeronave($request, $aircraft);
        abort_if((int) $document->aircraft_id !== (int) $aircraft->id, 404);

        return $this->streamAircraftDocument($document);
    }

    public function downloadAdminDocument(Request $request, DocumentoAeronave $document)
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        return $this->streamAircraftDocument($document);
    }

    private function streamAircraftDocument(DocumentoAeronave $document)
    {
        $disk = (string) ($document->storage_disk ?: 's3');
        $path = (string) ($document->storage_path ?: '');

        if ($path !== '') {
            $storage = Storage::disk($disk);
            abort_unless($storage->exists($path), 404, 'Documento no encontrado.');

            $fileName = $document->document_name ?: basename($path);

            return $storage->response($path, $fileName, [], 'inline');
        }

        $url = $document->document_url ?: $document->file_url;
        abort_unless($url, 404, 'Documento sin archivo asociado.');

        $s3Path = $this->resolveS3Path($url);
        if ($s3Path) {
            $storage = Storage::disk('s3');
            abort_unless($storage->exists($s3Path), 404, 'Documento no encontrado.');

            return $storage->response($s3Path, $document->document_name ?: basename($s3Path), [], 'inline');
        }

        return redirect()->away($url);
    }

    public function availability(Request $request)
    {
        $query = DisponibilidadAeronave::with('aircraft');

        if ($request->user()->hasRole('provider') && ! $request->user()->hasRole('admin')) {
            $query->whereHas('aircraft', fn ($scope) => $scope->where('provider_id', $request->user()->resolvedProviderId()));
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


    private function storeAircraftDocumentFile(Aeronave $aircraft, UploadedFile $file, string $documentType, ?string $expiresAt, ?string $documentName, ?string $notes): DocumentoAeronave
    {
        $kind = $this->resolveDocumentFileKind($file);
        abort_if(! in_array($kind, ['image', 'pdf'], true), 422, 'Formato no permitido. Usa imagen o PDF.');
        abort_if($kind === 'image' && $file->getSize() > 8 * 1024 * 1024, 422, 'Las imagenes no pueden superar 8MB.');
        abort_if($kind === 'pdf' && $file->getSize() > 25 * 1024 * 1024, 422, 'Los PDF no pueden superar 25MB.');

        $safeType = Str::slug($documentType, '_') ?: 'documento';
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_') ?: 'archivo';
        $basePath = sprintf('provider/%s/aircraft/%s/documents/%s', $aircraft->provider_id, $aircraft->id, $safeType);
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'original_mime' => $file->getMimeType(),
            'original_size' => $file->getSize(),
            'variants' => [],
        ];

        $stored = $kind === 'image'
            ? $this->storeImageDocumentVariants($file, $basePath, $safeName)
            : $this->storePdfDocument($file, $basePath, $safeName);

        $metadata['variants'] = $stored['variants'] ?? [];
        $metadata['processed'] = $stored['processed'] ?? false;

        return $aircraft->documents()->create([
            'provider_id' => $aircraft->provider_id,
            'type' => $documentType,
            'document_type' => $documentType,
            'document_name' => $documentName ?: $file->getClientOriginalName(),
            'file_type' => $stored['mime'] ?? $file->getMimeType(),
            'file_url' => $stored['url'],
            'document_url' => $stored['url'],
            'thumbnail_url' => $stored['thumbnail_url'] ?? null,
            'storage_disk' => $stored['disk'] ?? $this->resolveUploadDisk(),
            'storage_path' => $stored['path'],
            'thumbnail_path' => $stored['thumbnail_path'] ?? null,
            'expires_at' => $expiresAt,
            'status' => 'pending',
            'verified_by_admin' => false,
            'notes' => $notes,
            'metadata' => $metadata,
        ]);
    }

    private function resolveDocumentFileKind(UploadedFile $file): string
    {
        $mime = strtolower((string) $file->getMimeType());
        $extension = strtolower($file->getClientOriginalExtension());

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            return 'pdf';
        }

        if (str_starts_with($mime, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], true)) {
            return 'image';
        }

        return 'other';
    }

    private function storeImageDocumentVariants(UploadedFile $file, string $basePath, string $safeName): array
    {
        $disk = $this->resolveUploadDisk();

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            [$resolvedDisk, $path] = $this->storeUploadedFileWithFallback(
                $file,
                $basePath.'/original',
                $safeName.'.'.$file->getClientOriginalExtension(),
                $disk,
                'No se pudo subir el documento de imagen al almacenamiento.'
            );
            return [
                'disk' => $resolvedDisk,
                'path' => $path,
                'url' => $this->resolveStoredFileUrl($resolvedDisk, $path),
                'mime' => $file->getMimeType(),
                'processed' => false,
            ];
        }

        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $source) {
            [$resolvedDisk, $path] = $this->storeUploadedFileWithFallback(
                $file,
                $basePath.'/original',
                $safeName.'.'.$file->getClientOriginalExtension(),
                $disk,
                'No se pudo subir el documento de imagen al almacenamiento.'
            );
            return [
                'disk' => $resolvedDisk,
                'path' => $path,
                'url' => $this->resolveStoredFileUrl($resolvedDisk, $path),
                'mime' => $file->getMimeType(),
                'processed' => false,
            ];
        }

        $resolvedDisk = $disk;
        $paths = [];
        foreach (['original' => 1600, 'medium' => 800, 'thumb' => 300] as $variant => $maxSide) {
            $binary = $this->resizeImageToWebp($source, $maxSide);
            $path = sprintf('%s/%s/%s-%s.webp', $basePath, $variant, $safeName, $variant);
            [$resolvedDisk, $storedPath] = $this->storeBinaryWithFallback(
                $path,
                $binary,
                ['visibility' => 'public', 'ContentType' => 'image/webp'],
                $resolvedDisk,
                'No se pudo subir una variante del documento de imagen al almacenamiento.'
            );
            if ($variant === 'original') {
                $paths = [];
            }
            $paths[$variant] = $storedPath;
        }

        imagedestroy($source);

        return [
            'disk' => $resolvedDisk,
            'path' => $paths['original'],
            'url' => $this->resolveStoredFileUrl($resolvedDisk, $paths['original']),
            'thumbnail_path' => $paths['thumb'],
            'thumbnail_url' => $this->resolveStoredFileUrl($resolvedDisk, $paths['thumb']),
            'mime' => 'image/webp',
            'processed' => true,
            'variants' => collect($paths)->map(fn ($path) => $this->resolveStoredFileUrl($resolvedDisk, $path))->all(),
        ];
    }

    private function resizeImageToWebp($source, int $maxSide): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagewebp($canvas, null, 84);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        return $binary;
    }

    private function storePdfDocument(UploadedFile $file, string $basePath, string $safeName): array
    {
        $disk = $this->resolveUploadDisk();
        [$resolvedDisk, $storedPath] = $this->storeUploadedFileWithFallback(
            $file,
            $basePath.'/original',
            $safeName.'.pdf',
            $disk,
            'No se pudo subir el PDF al almacenamiento.'
        );

        return [
            'disk' => $resolvedDisk,
            'path' => $storedPath,
            'url' => $this->resolveStoredFileUrl($resolvedDisk, $storedPath),
            'mime' => 'application/pdf',
            'processed' => false,
        ];
    }

    private function guessFileTypeFromUrl(string $url): ?string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => null,
        };
    }

    private function normalizeAircraftInput(array $data, ?Aeronave $aircraft = null): array
    {
        if (! array_key_exists('speed_kmh', $data) && array_key_exists('speed_knots', $data)) {
            $data['speed_kmh'] = (int) round(((float) $data['speed_knots']) * 1.852);
        }

        unset($data['speed_knots']);

        if (! array_key_exists('model_year', $data) && array_key_exists('year', $data)) {
            $data['model_year'] = $data['year'];
        }

        unset($data['year']);

        if (array_key_exists('manufacturer', $data)) {
            $data['manufacturer'] = $this->normalizeNullableString($data['manufacturer']);
        }

        if (array_key_exists('category', $data)) {
            $data['category'] = $this->normalizeAircraftCategory($data['category']);
        }

        $resolvedCategory = $data['category'] ?? $this->normalizeAircraftCategory($aircraft?->category);
        if ($resolvedCategory && (! array_key_exists('climb_descent_minutes', $data) || (int) ($data['climb_descent_minutes'] ?? 0) <= 0)) {
            $data['climb_descent_minutes'] = $this->resolveClimbDescentMinutesForCategory($resolvedCategory);
        }

        if (array_key_exists('coverage', $data)) {
            $data['coverage'] = $this->normalizeNullableString($data['coverage']);
        }

        if (array_key_exists('base_airport', $data)) {
            $data['base_airport'] = $this->normalizeNullableString($data['base_airport']);
            $data['base_airport_id'] = $this->findAirportByCode($data['base_airport'])?->id;
        }

        if (array_key_exists('registration', $data)) {
            $data['registration'] = $this->normalizeRegistration($data['registration']);
        }

        if (array_key_exists('model', $data)) {
            $data['model'] = $this->normalizeNullableString($data['model']);
        }

        if (array_key_exists('amenities', $data)) {
            $data['amenities'] = $this->normalizeAmenities($data['amenities']);
        }

        return $data;
    }

    private function rules(bool $creating = true, ?Aeronave $aircraft = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'model' => [$required, 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::in(self::AIRCRAFT_CATEGORIES)],
            'model_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'registration' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('aircraft', 'registration')->ignore($aircraft?->id),
            ],
            'capacity' => [$required, 'integer', 'min:1'],
            'base_airport' => [$required, 'string', 'max:20'],
            'range_km' => ['nullable', 'integer', 'min:0'],
            'speed_kmh' => ['nullable', 'integer', 'min:0'],
            'speed_knots' => ['nullable', 'numeric', 'min:0'],
            'coverage' => ['nullable', 'string', 'max:255'],
            'amenities' => ['nullable'],
            'hourly_rate' => [$required, 'numeric', 'min:0'],
            'airport_expenses_usd' => ['nullable', 'numeric', 'min:0'],
            'minimum_hours' => ['nullable', 'numeric', 'min:0'],
            'minimum_route_price' => ['nullable', 'numeric', 'min:0'],
            'climb_descent_minutes' => ['nullable', 'integer', 'min:0'],
            'operational_cost' => ['nullable', 'numeric', 'min:0'],
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

    private function formatAircraftPayload(Aeronave $aircraft, mixed $planOverride = null, ?int $providerAircraftCountOverride = null): array
    {
        $providerUser = $aircraft->provider?->user;
        $plan = $planOverride ?? $providerUser?->activeSuscripcion?->plan;
        $providerAircraftCount = $providerAircraftCountOverride
            ?? $aircraft->provider?->aircraft_count
            ?? ($aircraft->provider?->aircraft()->count() ?? 1);
        $monthlyBase = (float) ($plan?->price_monthly ?? $plan?->price_yearly ?? $plan?->price ?? 0);
        $monthlyPerAircraft = $providerAircraftCount > 0 && $monthlyBase > 0
            ? round($monthlyBase / $providerAircraftCount, 2)
            : null;
        $images = $aircraft->images
            ->sortBy([
                ['is_main', 'desc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        return [
            ...$aircraft->attributesToArray(),
            'base_airport' => $aircraft->resolvedBaseAirportCode(),
            'year' => $aircraft->model_year,
            'climb_descent_minutes' => $aircraft->climb_descent_minutes ?: $this->resolveClimbDescentMinutesForCategory($aircraft->category),
            'amenities' => $this->parseAmenities($aircraft->amenities),
            'provider' => $aircraft->provider ? [
                'id' => $aircraft->provider->id,
                'user_id' => $aircraft->provider->user_id,
                'company_name' => $aircraft->provider->company_name,
                'commercial_name' => $aircraft->provider->commercial_name,
            ] : null,
            'documents' => $aircraft->documents
                ->map(fn (DocumentoAeronave $document) => $this->formatAircraftDocumentPayload($document))
                ->values(),
            'main_image' => $images->firstWhere('is_main', true)?->image_url ?? $images->first()?->image_url,
            'images' => $images->map(fn (ImagenAeronave $image) => [
                'id' => $image->id,
                'kind' => $image->kind,
                'title' => $image->title,
                'image_url' => $image->image_url,
                'is_main' => $image->is_main,
                'visible_to_client' => $image->visible_to_client,
                'sort_order' => $image->sort_order,
            ])->values(),
            'membership_context' => $plan ? [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_cycle' => $plan->billing_cycle,
                'max_aircraft' => $plan->max_aircraft,
                'monthly_cost_per_aircraft' => $monthlyPerAircraft,
                'within_plan_limit' => $plan->max_aircraft ? $providerAircraftCount <= $plan->max_aircraft : true,
            ] : null,
            'billing_status' => $aircraft->billing_status,
            'billing_plan_id' => $aircraft->billing_plan_id,
            'subscription_status' => $aircraft->subscription_status,
            'subscription_started_at' => $aircraft->subscription_started_at,
            'subscription_ends_at' => $aircraft->subscription_ends_at,
            'last_payment_at' => $aircraft->last_payment_at,
        ];
    }

    private function authorizeProveedorAeronave(Request $request, Aeronave $aircraft): void
    {
        if ($request->user()->hasRole('admin')) {
            return;
        }

        abort_if($aircraft->provider_id !== $request->user()->resolvedProviderId(), 403, 'No puedes gestionar esta aeronave.');
    }

    private function formatPublicAircraftPayload(Aeronave $aircraft): array
    {
        $sortedImages = $aircraft->images
            ->filter(fn (ImagenAeronave $image) => filled($image->image_url))
            ->sortBy([
                ['is_main', 'desc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $visibleImages = $sortedImages
            ->where('visible_to_client', true)
            ->values();

        if ($visibleImages->isEmpty()) {
            $visibleImages = $sortedImages;
        }

        $mainImage = $visibleImages->firstWhere('is_main', true)?->image_url
            ?? $visibleImages->first()?->image_url;

        return [
            'id' => $aircraft->id,
            'model' => $aircraft->model,
            'category' => $this->normalizeAircraftCategory($aircraft->category) ?? 'Cabina ejecutiva',
            'capacity' => $aircraft->capacity,
            'base_airport' => $aircraft->resolvedBaseAirportCode(),
            'range_km' => $aircraft->range_km,
            'climb_descent_minutes' => $aircraft->climb_descent_minutes ?: $this->resolveClimbDescentMinutesForCategory($aircraft->category),
            'status' => $aircraft->status,
            'manufacturer' => $aircraft->manufacturer,
            'coverage' => $aircraft->coverage,
            'year' => $aircraft->model_year,
            'main_image' => $mainImage,
            'images' => $visibleImages->map(fn (ImagenAeronave $image) => [
                'id' => $image->id,
                'kind' => $image->kind,
                'title' => $image->title,
                'image_url' => $image->image_url,
                'is_main' => $image->is_main,
            ])->values(),
            'amenities' => $this->parseAmenities($aircraft->amenities),
        ];
    }

    private function normalizeAmenities(mixed $value): ?string
    {
        if (is_array($value)) {
            $items = array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $value
            )));

            return $items === [] ? null : implode(', ', $items);
        }

        $normalized = $this->normalizeNullableString($value);
        return $normalized === null ? null : $normalized;
    }

    private function parseAmenities(mixed $value): array
    {
        $normalized = $this->normalizeNullableString($value);
        if ($normalized === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $normalized))));
    }

    private function resolveClimbDescentMinutesForCategory(?string $category): int
    {
        return self::CATEGORY_CLIMB_DESCENT_MINUTES[$this->normalizeAircraftCategory($category) ?? ''] ?? 30;
    }

    private function normalizeAircraftCategory(mixed $value): ?string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')));

        return match ($normalized) {
            'helicoptero', 'helicóptero', 'helicopter' => 'Helicoptero',
            'turboprop', 'turbo prop' => 'Turboprop',
            'light jet', 'light_jet', 'lightjet' => 'Light Jet',
            'mid jet', 'mid_jet', 'midjet', 'midsize jet', 'midsize_jet', 'super mid', 'super_mid' => 'Mid Jet',
            'heavy jet', 'heavy_jet', 'heavyjet', 'long range', 'long_range', 'ultra long', 'ultra_long' => 'Heavy Jet',
            '' => null,
            default => trim((string) $value),
        };
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeRegistration(mixed $value): ?string
    {
        $registration = $this->normalizeNullableString($value);
        if ($registration === null) {
            return null;
        }

        return preg_match('/^PENDIENTE\d*$/i', $registration) ? null : $registration;
    }

    private function formatAircraftDocumentPayload(DocumentoAeronave $document): array
    {
        $resolvedUrl = $this->resolveAircraftDocumentUrl($document);
        $resolvedThumbnailUrl = $this->resolveAircraftDocumentThumbnailUrl($document);

        return [
            ...$document->toArray(),
            'file_url' => $resolvedUrl,
            'document_url' => $resolvedUrl,
            'url' => $resolvedUrl,
            'thumbnail_url' => $resolvedThumbnailUrl,
        ];
    }

    private function resolveAircraftDocumentUrl(DocumentoAeronave $document): string
    {
        $disk = (string) ($document->storage_disk ?: 'public');
        $path = (string) ($document->storage_path ?: '');

        if ($disk === 's3' && $path !== '' && $this->canGenerateTemporaryS3Urls()) {
            try {
                return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(30));
            } catch (\Throwable) {
                return $document->document_url ?: $document->file_url ?: '';
            }
        }

        return $document->document_url ?: $document->file_url ?: '';
    }

    private function resolveAircraftDocumentThumbnailUrl(DocumentoAeronave $document): ?string
    {
        $thumbnailPath = (string) ($document->thumbnail_path ?: '');
        $thumbnailUrl = $document->thumbnail_url ?: null;
        $disk = (string) ($document->storage_disk ?: 'public');

        if ($disk === 's3' && $thumbnailPath !== '' && $this->canGenerateTemporaryS3Urls()) {
            try {
                return Storage::disk('s3')->temporaryUrl($thumbnailPath, now()->addMinutes(30));
            } catch (\Throwable) {
                return $thumbnailUrl;
            }
        }

        return $thumbnailUrl;
    }

    private function canGenerateTemporaryS3Urls(): bool
    {
        $key = trim((string) config('filesystems.disks.s3.key', ''));
        $secret = trim((string) config('filesystems.disks.s3.secret', ''));
        $bucket = trim((string) config('filesystems.disks.s3.bucket', ''));
        $region = trim((string) config('filesystems.disks.s3.region', ''));

        return $key !== '' && $secret !== '' && $bucket !== '' && $region !== '';
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

    private function resolveUploadDisk(): string
    {
        $missingVariables = $this->missingS3UploadConfigurationVariables();

        abort_if(
            $missingVariables !== [],
            500,
            'Falta configuracion de AWS S3 en el servidor. Variables faltantes o vacias: '.implode(', ', $missingVariables).'.'
        );

        return 's3';
    }

    private function missingS3UploadConfigurationVariables(): array
    {
        $required = [
            'AWS_ACCESS_KEY_ID' => config('filesystems.disks.s3.key'),
            'AWS_SECRET_ACCESS_KEY' => config('filesystems.disks.s3.secret'),
            'AWS_BUCKET' => config('filesystems.disks.s3.bucket'),
            'AWS_DEFAULT_REGION' => config('filesystems.disks.s3.region'),
        ];

        return array_keys(array_filter(
            $required,
            fn ($value) => $this->isMissingS3ConfigValue($value)
        ));
    }

    private function isMissingS3ConfigValue(mixed $value): bool
    {
        $normalized = trim((string) $value);

        return $normalized === ''
            || str_starts_with($normalized, 'TU_NUEVA_')
            || str_starts_with($normalized, 'your_')
            || str_starts_with($normalized, 'YOUR_');
    }

    private function storeBinaryWithFallback(string $path, string $contents, array $options, ?string $preferredDisk, string $errorMessage): array
    {
        $disk = $preferredDisk ?: $this->resolveUploadDisk();
        $options = $this->normalizeStorageOptions($disk, $options);

        try {
            $stored = Storage::disk($disk)->put($path, $contents, $options);
            abort_if(! $stored, 500, $errorMessage.' Disco probado: '.$disk.'. S3 devolvio false al guardar.');
            return [$disk, $path];
        } catch (\Throwable $exception) {
            Log::error('Fallo al almacenar archivo en S3.', [
                'disk' => $disk,
                'path' => $path,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]);

            abort(500, $this->formatStorageFailureMessage($errorMessage, $disk, $exception));
        }
    }

    private function storeUploadedFileWithFallback(UploadedFile $file, string $directory, string $filename, ?string $preferredDisk, string $errorMessage): array
    {
        $disk = $preferredDisk ?: $this->resolveUploadDisk();

        try {
            $path = $file->storeAs($directory, $filename, $disk);
            abort_if(! $path, 500, $errorMessage.' Disco probado: '.$disk.'. S3 devolvio false al guardar.');
            return [$disk, $path];
        } catch (\Throwable $exception) {
            Log::error('Fallo al subir archivo a S3.', [
                'disk' => $disk,
                'directory' => $directory,
                'filename' => $filename,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]);

            abort(500, $this->formatStorageFailureMessage($errorMessage, $disk, $exception));
        }
    }

    private function formatStorageFailureMessage(string $errorMessage, string $disk, \Throwable $exception): string
    {
        $details = array_values(array_filter([
            class_basename($exception).': '.$exception->getMessage(),
            $exception->getPrevious()
                ? class_basename($exception->getPrevious()).': '.$exception->getPrevious()->getMessage()
                : null,
        ]));

        $message = $errorMessage.' Disco probado: '.$disk.'.';

        if ($details !== []) {
            $message .= ' Detalle: '.implode(' | ', $details);
        }

        return $message;
    }

    private function normalizeStorageOptions(string $disk, array $options): array
    {
        if ($disk !== 's3') {
            return $options;
        }

        $normalized = $options;
        unset($normalized['visibility']);

        return $normalized;
    }

    private function resolveUniqueDocumentUploads(Request $request): array
    {
        $files = [];

        foreach ((array) $request->file('documents', []) as $file) {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        }

        foreach (['file', 'document'] as $field) {
            $file = $request->file($field);
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        }

        $unique = [];

        foreach ($files as $file) {
            $key = implode('|', [
                $file->getClientOriginalName(),
                (string) $file->getSize(),
                (string) $file->getMimeType(),
                (string) $file->getRealPath(),
            ]);
            $unique[$key] = $file;
        }

        return array_values($unique);
    }

    private function resolveStoredFileUrl(string $disk, string $path): string
    {
        $url = Storage::disk($disk)->url($path);

        if ($disk !== 'public' || preg_match('/^(https?:)?\/\//i', $url)) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').$url;
    }

    private function resolveUploadedFileUrl(Request $request, string $disk, string|false $path): string
    {
        if (! $path) {
            return '';
        }

        $url = Storage::disk($disk)->url($path);

        if ($disk !== 'public' || preg_match('/^(https?:)?\/\//i', $url)) {
            return $url;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/').$url;
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

    private function findAirportByCode(?string $code): ?Aeropuerto
    {
        $normalizedCode = strtoupper(trim((string) $code));

        if ($normalizedCode === '') {
            return null;
        }

        return Aeropuerto::query()
            ->where('icao', $normalizedCode)
            ->orWhere('iata', $normalizedCode)
            ->first();
    }
}
