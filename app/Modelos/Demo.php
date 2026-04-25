<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Demo extends Model
{
    protected $table = 'demos';

    protected $fillable = ['user_id', 'started_at', 'expires_at', 'status'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
