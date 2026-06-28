<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notificacion extends Model
{
    protected $table = 'notifications';

    protected $fillable = ['user_id', 'provider_id', 'type', 'title', 'message', 'payload', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
