<?php

namespace App\Servicios\Identidad;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class IdentityStorageServicio
{
    public function diskName(): string
    {
        $disk = trim((string) config('filesystems.identity_disk', 'private'));

        if ($disk === '' || config("filesystems.disks.{$disk}") === null) {
            throw new \RuntimeException('El disk de documentos de identidad no esta configurado.');
        }

        return $disk;
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk($this->diskName());
    }

    public function store(UploadedFile $file, string $directory): string
    {
        return $this->confirmStored(
            $file->store($directory, $this->diskName()),
            $directory,
            $file
        );
    }

    public function storeAs(UploadedFile $file, string $directory, string $filename): string
    {
        return $this->confirmStored(
            $file->storeAs($directory, $filename, $this->diskName()),
            $directory,
            $file
        );
    }

    public function exists(string $path): bool
    {
        return $path !== '' && $this->disk()->exists($path);
    }

    public function delete(string $path): bool
    {
        if ($path === '' || ! $this->exists($path)) {
            return true;
        }

        return $this->disk()->delete($path) && ! $this->exists($path);
    }

    public function mimeType(string $path): ?string
    {
        return $this->exists($path) ? $this->disk()->mimeType($path) : null;
    }

    private function confirmStored(mixed $path, string $directory, UploadedFile $file): string
    {
        $disk = $this->diskName();

        if (! is_string($path) || $path === '' || ! $this->exists($path)) {
            if (is_string($path) && $path !== '') {
                try {
                    $this->disk()->delete($path);
                } catch (\Throwable $cleanupException) {
                    Log::warning('[IDENTITY_STORAGE_CLEANUP_FAILED]', [
                        'disk' => $disk,
                        'path' => $path,
                        'message' => $cleanupException->getMessage(),
                    ]);
                }
            }

            Log::error('[IDENTITY_STORAGE_WRITE_FAILED]', [
                'disk' => $disk,
                'directory' => $directory,
                'original_name' => $file->getClientOriginalName(),
            ]);

            throw new \RuntimeException('No fue posible guardar el documento de identidad.');
        }

        return $path;
    }
}
