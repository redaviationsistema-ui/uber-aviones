<?php

namespace App\Servicios\Administracion;

use App\Modelos\DocumentoAeronave;
use App\Modelos\DocumentoEmpresa;
use App\Modelos\Usuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDocumentosServicio
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $records = collect()
            ->merge($this->providerDocuments($filters))
            ->merge($this->aircraftDocuments($filters))
            ->sortByDesc('created_at')
            ->values();

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $slice = $records->forPage($page, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $records->count(),
            $perPage,
            $page,
            ['path' => request()?->url() ?? '']
        );
    }

    public function show(string $reference): array
    {
        [$type, $document] = $this->resolveReference($reference);

        return $type === 'provider'
            ? $this->serializeProviderDocument($document)
            : $this->serializeAircraftDocument($document);
    }

    public function approve(string $reference, string $reason): array
    {
        return $this->changeReviewStatus($reference, 'approved', $reason);
    }

    public function reject(string $reference, string $reason): array
    {
        return $this->changeReviewStatus($reference, 'rejected', $reason);
    }

    public function requestCorrection(string $reference, string $reason): array
    {
        return $this->changeReviewStatus($reference, 'changes_requested', $reason);
    }

    public function download(string $reference): StreamedResponse
    {
        [$type, $document] = $this->resolveReference($reference);

        $disk = trim((string) (($document->storage_disk ?? 's3'))) ?: 's3';
        $path = trim((string) ($document->storage_path ?? ''));

        abort_if($path === '', 404, 'Documento sin archivo privado disponible.');

        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404, 'Archivo no encontrado.');

        return $storage->response(
            $path,
            $document->file_name ?? $document->original_name ?? $document->document_name ?? basename($path),
            ['Content-Type' => $document->mime_type ?? 'application/octet-stream'],
            'inline'
        );
    }

    private function changeReviewStatus(string $reference, string $status, string $reason): array
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => ['El motivo es obligatorio para revisar documentos administrativos.'],
            ]);
        }

        [$type, $document] = $this->resolveReference($reference);

        if ($type === 'provider') {
            $before = ['status' => $document->status, 'notes' => $document->notes];
            $document->update(['status' => $status, 'notes' => $reason]);
            return ['type' => $type, 'before' => $before, 'after' => ['status' => $document->status, 'notes' => $document->notes], 'document' => $this->serializeProviderDocument($document->fresh())];
        }

        $before = ['status' => $document->status, 'notes' => $document->notes, 'verified_by_admin' => $document->verified_by_admin];
        $document->update([
            'status' => $status,
            'notes' => $reason,
            'verified_by_admin' => $status === 'approved',
        ]);

        return ['type' => $type, 'before' => $before, 'after' => ['status' => $document->status, 'notes' => $document->notes, 'verified_by_admin' => $document->verified_by_admin], 'document' => $this->serializeAircraftDocument($document->fresh())];
    }

    private function providerDocuments(array $filters): Collection
    {
        return DocumentoEmpresa::query()
            ->with('provider:id,company_name,commercial_name')
            ->when(filled($filters['review_status'] ?? null), fn ($query) => $query->where('status', $filters['review_status']))
            ->when(filled($filters['expires_before'] ?? null), fn ($query) => $query->whereDate('expires_at', '<=', $filters['expires_before']))
            ->when(($filters['provider_id'] ?? null) !== null, fn ($query) => $query->where('provider_id', (int) $filters['provider_id']))
            ->latest('id')
            ->get()
            ->map(fn (DocumentoEmpresa $document) => $this->serializeProviderDocument($document));
    }

    private function aircraftDocuments(array $filters): Collection
    {
        return DocumentoAeronave::query()
            ->with(['provider:id,company_name,commercial_name', 'aircraft:id,provider_id,model,registration'])
            ->when(filled($filters['review_status'] ?? null), fn ($query) => $query->where('status', $filters['review_status']))
            ->when(filled($filters['expires_before'] ?? null), fn ($query) => $query->whereDate('expires_at', '<=', $filters['expires_before']))
            ->when(($filters['provider_id'] ?? null) !== null, fn ($query) => $query->where('provider_id', (int) $filters['provider_id']))
            ->when(($filters['aircraft_id'] ?? null) !== null, fn ($query) => $query->where('aircraft_id', (int) $filters['aircraft_id']))
            ->latest('id')
            ->get()
            ->map(fn (DocumentoAeronave $document) => $this->serializeAircraftDocument($document));
    }

    private function resolveReference(string $reference): array
    {
        [$type, $id] = array_pad(explode(':', $reference, 2), 2, null);
        abort_if(! $type || ! $id, 404, 'Documento administrativo no encontrado.');

        return match ($type) {
            'provider' => ['provider', DocumentoEmpresa::query()->findOrFail((int) $id)],
            'aircraft' => ['aircraft', DocumentoAeronave::query()->findOrFail((int) $id)],
            default => abort(404, 'Documento administrativo no soportado.'),
        };
    }

    private function serializeProviderDocument(DocumentoEmpresa $document): array
    {
        return [
            'id' => 'provider:'.$document->id,
            'document_id' => $document->id,
            'document_type' => 'provider',
            'owner_id' => $document->provider_id,
            'provider_id' => $document->provider_id,
            'aircraft_id' => null,
            'review_status' => $document->status,
            'title' => $document->document_name ?: $document->original_name ?: 'Documento de proveedor',
            'file_name' => $document->file_name,
            'expires_at' => optional($document->expires_at)->toIso8601String(),
            'created_at' => optional($document->created_at)->toIso8601String(),
            'owner_name' => $document->provider?->commercial_name ?: $document->provider?->company_name,
            'notes' => $document->notes,
            'download_path' => '/admin/documents/provider:'.$document->id.'/download',
        ];
    }

    private function serializeAircraftDocument(DocumentoAeronave $document): array
    {
        return [
            'id' => 'aircraft:'.$document->id,
            'document_id' => $document->id,
            'document_type' => 'aircraft',
            'owner_id' => $document->aircraft_id,
            'provider_id' => $document->provider_id,
            'aircraft_id' => $document->aircraft_id,
            'review_status' => $document->status,
            'title' => $document->document_name ?: $document->document_type ?: 'Documento de aeronave',
            'file_name' => basename((string) ($document->storage_path ?: $document->document_url ?: $document->file_url ?: '')),
            'expires_at' => optional($document->expires_at)->toIso8601String(),
            'created_at' => optional($document->created_at)->toIso8601String(),
            'owner_name' => trim((string) (($document->aircraft?->registration ?? '').' '.($document->aircraft?->model ?? ''))),
            'notes' => $document->notes,
            'download_path' => '/admin/documents/aircraft:'.$document->id.'/download',
        ];
    }
}
