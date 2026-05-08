<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\DisponibilidadAeronave;
use App\Modelos\DocumentoAeronave;
use App\Modelos\ImagenAeronave;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
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
        abort_if(! $provider, 422, 'El usuario proveedor no tiene provider_id asignado.');

        $data = $request->validate($this->rules());
        $isApproved = $provider->approval_status === 'approved';
        $aircraft = $provider->aircraft()->create($data + [
            'status' => $isApproved ? 'active' : 'blocked',
        ]);

        return $this->ok([
            'aircraft' => $this->formatAircraftPayload(
                $aircraft->fresh(['provider.user.activeSuscripcion.plan', 'availability', 'documents', 'images'])
            ),
            'message' => $isApproved
                ? 'La aeronave fue registrada y quedó activa.'
                : 'La aeronave fue registrada y quedó bloqueada hasta activación admin.',
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

        $disk = $this->resolveUploadDisk();
        $path = $request->file('image')->store('aircraft', $disk);
        $imageUrl = $this->resolveUploadedFileUrl($request, $disk, $path);
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
        $uploadedFiles = $request->file('documents') ?: [];
        if ($request->hasFile('file')) {
            $uploadedFiles[] = $request->file('file');
        }

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
            'storage_disk' => 's3',
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
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            $path = $file->storeAs($basePath.'/original', $safeName.'.'.$file->getClientOriginalExtension(), 's3');
            return [
                'path' => $path,
                'url' => Storage::disk('s3')->url($path),
                'mime' => $file->getMimeType(),
                'processed' => false,
            ];
        }

        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $source) {
            $path = $file->storeAs($basePath.'/original', $safeName.'.'.$file->getClientOriginalExtension(), 's3');
            return [
                'path' => $path,
                'url' => Storage::disk('s3')->url($path),
                'mime' => $file->getMimeType(),
                'processed' => false,
            ];
        }

        $paths = [];
        foreach (['original' => 1600, 'medium' => 800, 'thumb' => 300] as $variant => $maxSide) {
            $binary = $this->resizeImageToWebp($source, $maxSide);
            $path = sprintf('%s/%s/%s-%s.webp', $basePath, $variant, $safeName, $variant);
            Storage::disk('s3')->put($path, $binary, ['visibility' => 'public', 'ContentType' => 'image/webp']);
            $paths[$variant] = $path;
        }

        imagedestroy($source);

        return [
            'path' => $paths['original'],
            'url' => Storage::disk('s3')->url($paths['original']),
            'thumbnail_path' => $paths['thumb'],
            'thumbnail_url' => Storage::disk('s3')->url($paths['thumb']),
            'mime' => 'image/webp',
            'processed' => true,
            'variants' => collect($paths)->map(fn ($path) => Storage::disk('s3')->url($path))->all(),
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
        $optimizedPath = $this->optimizePdfIfPossible($file->getRealPath());
        $path = sprintf('%s/original/%s.pdf', $basePath, $safeName);
        Storage::disk('s3')->put($path, file_get_contents($optimizedPath), ['visibility' => 'public', 'ContentType' => 'application/pdf']);

        return [
            'path' => $path,
            'url' => Storage::disk('s3')->url($path),
            'mime' => 'application/pdf',
            'processed' => $optimizedPath !== $file->getRealPath(),
        ];
    }

    private function optimizePdfIfPossible(string $sourcePath): string
    {
        $targetPath = tempnam(sys_get_temp_dir(), 'ra_pdf_').'.pdf';

        if ($this->commandExists('qpdf')) {
            $command = sprintf('qpdf --linearize %s %s', escapeshellarg($sourcePath), escapeshellarg($targetPath));
            exec($command, $output, $exitCode);
            if ($exitCode === 0 && is_file($targetPath) && filesize($targetPath) > 0) {
                return $targetPath;
            }
        }

        if ($this->commandExists('gs')) {
            $command = sprintf(
                'gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dQUIET -dBATCH -sOutputFile=%s %s',
                escapeshellarg($targetPath),
                escapeshellarg($sourcePath)
            );
            exec($command, $output, $exitCode);
            if ($exitCode === 0 && is_file($targetPath) && filesize($targetPath) > 0) {
                return $targetPath;
            }
        }

        return $sourcePath;
    }

    private function commandExists(string $command): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $probe = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
            ? sprintf('where %s', escapeshellarg($command))
            : sprintf('command -v %s', escapeshellarg($command));
        exec($probe, $output, $exitCode);

        return $exitCode === 0;
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
        $images = $aircraft->images
            ->sortBy([
                ['is_main', 'desc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        return [
            ...$aircraft->toArray(),
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

    private function resolveUploadDisk(): string
    {
        $defaultDisk = (string) config('filesystems.default', 'public');

        if ($defaultDisk !== 's3') {
            return $defaultDisk;
        }

        $hasS3Credentials = filled(env('AWS_ACCESS_KEY_ID')) && filled(env('AWS_SECRET_ACCESS_KEY')) && filled(env('AWS_BUCKET'));

        return $hasS3Credentials ? 's3' : 'public';
    }

    private function resolveUploadedFileUrl(Request $request, string $disk, string|false $path): string
    {
        if (! $path) {
            return '';
        }

        if ($disk !== 'public') {
            return Storage::disk($disk)->url($path);
        }

        return rtrim($request->getSchemeAndHttpHost(), '/').Storage::disk('public')->url($path);
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

