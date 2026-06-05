<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogoDisponibilidadEstatus extends Model
{
    protected $table = 'catalogo_disponibilidad_estatus';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'color',
        'icono',
        'orden',
        'seleccionable_sobrecargo',
        'seleccionable_admin',
        'permite_asignacion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'seleccionable_sobrecargo' => 'boolean',
            'seleccionable_admin' => 'boolean',
            'permite_asignacion' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function disponibilidades(): HasMany
    {
        return $this->hasMany(SobrecargoDisponibilidad::class, 'estatus_id');
    }
}
