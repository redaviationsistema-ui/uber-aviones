<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionSobrecargo extends Model
{
    protected $table = 'sobrecargo_assignments';

    protected $fillable = ['operation_id', 'sobrecargo_user_id', 'status'];

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(Operacion::class, 'operation_id');
    }

    public function sobrecargo(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'sobrecargo_user_id');
    }
}
