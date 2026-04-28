<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineaTiempoOperacion extends Model
{
    protected $table = 'operation_timeline';

    protected $fillable = ['operation_id', 'status', 'title', 'description', 'created_by'];

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(Operacion::class, 'operation_id');
    }
}
