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
        $disk = $this->diskName();
        $path = $file->store($directory, $disk);

        if (! is_string($path) || $path === '' || ! Storage::disk($disk)->exists($path)) {
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
