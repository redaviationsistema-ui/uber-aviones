<?php

namespace Database\Seeders;

use App\Modelos\CatalogoDisponibilidadEstatus;
use Illuminate\Database\Seeder;

class CatalogoDisponibilidadEstatusSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->statuses() as $status) {
            CatalogoDisponibilidadEstatus::updateOrCreate(
                ['clave' => $status['clave']],
                $status
            );
        }
    }

    private function statuses(): array
    {
        return [
            [
                'clave' => 'DISPONIBLE',
                'nombre' => 'Disponible',
                'descripcion' => 'La sobrecargo puede ser asignada a una operacion.',
                'color' => 'green',
                'icono' => 'check-circle',
                'orden' => 1,
                'seleccionable_sobrecargo' => true,
                'seleccionable_admin' => true,
                'permite_asignacion' => true,
                'activo' => true,
            ],
            [
                'clave' => 'NO_DISPONIBLE',
                'nombre' => 'No disponible',
                'descripcion' => 'La sobrecargo no puede ser asignada.',
                'color' => 'red',
                'icono' => 'x-circle',
                'orden' => 2,
                'seleccionable_sobrecargo' => true,
                'seleccionable_admin' => true,
                'permite_asignacion' => false,
                'activo' => true,
            ],
            [
                'clave' => 'DESCANSO',
                'nombre' => 'Descanso',
                'descripcion' => 'Dia libre o descanso.',
                'color' => 'gray',
                'icono' => 'moon',
                'orden' => 3,
                'seleccionable_sobrecargo' => true,
                'seleccionable_admin' => true,
                'permite_asignacion' => false,
                'activo' => true,
            ],
            [
                'clave' => 'EN_OPERACION',
                'nombre' => 'En operacion',
                'descripcion' => 'La sobrecargo ya tiene una operacion asignada.',
                'color' => 'blue',
                'icono' => 'plane',
                'orden' => 4,
                'seleccionable_sobrecargo' => false,
                'seleccionable_admin' => true,
                'permite_asignacion' => false,
                'activo' => true,
            ],
            [
                'clave' => 'BLOQUEO_SOLICITADO',
                'nombre' => 'Bloqueo solicitado',
                'descripcion' => 'La sobrecargo solicito bloquear ese dia.',
                'color' => 'yellow',
                'icono' => 'alert-circle',
                'orden' => 5,
                'seleccionable_sobrecargo' => true,
                'seleccionable_admin' => true,
                'permite_asignacion' => false,
                'activo' => true,
            ],
            [
                'clave' => 'BLOQUEO_APROBADO',
                'nombre' => 'Bloqueo aprobado',
                'descripcion' => 'El bloqueo solicitado fue aprobado.',
                'color' => 'orange',
                'icono' => 'shield-check',
                'orden' => 6,
                'seleccionable_sobrecargo' => false,
                'seleccionable_admin' => true,
                'permite_asignacion' => false,
                'activo' => true,
            ],
            [
                'clave' => 'BLOQUEO_RECHAZADO',
                'nombre' => 'Bloqueo rechazado',
                'descripcion' => 'El bloqueo solicitado fue rechazado.',
                'color' => 'rose',
                'icono' => 'shield-x',
                'orden' => 7,
                'seleccionable_sobrecargo' => false,
                'seleccionable_admin' => true,
                'permite_asignacion' => true,
                'activo' => true,
            ],
            [
                'clave' => 'POR_CONFIRMAR',
                'nombre' => 'Por confirmar',
                'descripcion' => 'Pendiente de confirmacion operativa.',
                'color' => 'slate',
                'icono' => 'help-circle',
                'orden' => 8,
                'seleccionable_sobrecargo' => true,
                'seleccionable_admin' => true,
                'permite_asignacion' => false,
                'activo' => true,
            ],
        ];
    }
}
