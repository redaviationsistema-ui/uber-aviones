<?php

namespace App\Consola\Comandos;

use App\Modelos\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrarAlmacenamientoIdentidadComando extends Command
{
    protected $signature = 'identity:migrate-storage {--from=private : Disk de origen} {--to= : Disk de destino; por defecto IDENTITY_FILESYSTEM_DISK} {--dry-run : Solo reporta} {--overwrite : Sobrescribe objetos existentes}';

    protected $description = 'Copia INE y selfies al disk de identidad sin borrar origen ni cambiar paths en BD';

    public function handle(): int
    {
        $from = trim((string) $this->option('from'));
        $to = trim((string) ($this->option('to') ?: config('filesystems.identity_disk', 'private')));
        $dryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');

        if ($from === '' || $to === '' || config("filesystems.disks.{$from}") === null || config("filesystems.disks.{$to}") === null) {
            $this->error('El disk de origen o destino no esta configurado.');
            return self::FAILURE;
        }
        if ($from === $to) {
            $this->warn('El disk de origen y destino es el mismo; no hay nada que migrar.');
            return self::SUCCESS;
        }

        $source = Storage::disk($from);
        $destination = Storage::disk($to);
        $stats = ['copied' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0];

        Usuario::query()->with('profile')->orderBy('id')->chunkById(200, function ($users) use ($source, $destination, $dryRun, $overwrite, &$stats): void {
            foreach ($users as $user) {
                $paths = array_unique(array_filter([
                    $user->profile?->ine_front_path,
                    $user->profile?->ine_back_path,
                    $user->resolvedBiometricSelfiePath(),
                ]));
                foreach ($paths as $path) {
                    if (! $source->exists($path)) {
                        $stats['missing']++;
                        continue;
                    }
                    if ($destination->exists($path) && ! $overwrite) {
                        $stats['skipped']++;
                        continue;
                    }
                    if ($dryRun) {
                        $stats['copied']++;
                        continue;
                    }
                    $stream = null;
                    try {
                        $stream = $source->readStream($path);
                        if (! is_resource($stream) || ! $destination->writeStream($path, $stream, ['visibility' => 'private']) || ! $destination->exists($path)) {
                            $stats['failed']++;
                            continue;
                        }
                        $stats['copied']++;
                    } catch (\Throwable $exception) {
                        report($exception);
                        $stats['failed']++;
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                }
            }
        });

        $this->table(['Copiados', 'Omitidos', 'Faltantes', 'Fallidos'], [[$stats['copied'], $stats['skipped'], $stats['missing'], $stats['failed']]]);

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
