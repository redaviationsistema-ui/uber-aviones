<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
