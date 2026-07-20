<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SobrecargoDisponibilidad extends Model
{
    protected $table = 'sobrecargo_disponibilidades';

    protected $fillable = [
        'sobrecargo_id',
        'fecha',
        'estatus_id',
        'motivo',
        'comentario',
        'origen',
        'operacion_id',
        'aprobado_por',
        'aprobado_at',
        'created_by',
        'updated_by',
        'bitacora',
        'hora_inicio',
        'hora_fin',
        'tipo',
        'base',
        'inmediata',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'aprobado_at' => 'datetime',
            'bitacora' => 'array',
            'inmediata' => 'boolean',
        ];
    }

    public function estatus(): BelongsTo
    {
        return $this->belongsTo(CatalogoDisponibilidadEstatus::class, 'estatus_id');
    }

    public function sobrecargo(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'sobrecargo_id');
    }

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(Operacion::class, 'operacion_id');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprobado_por');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by');
    }
}
