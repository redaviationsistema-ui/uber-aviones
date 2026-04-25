<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

class Notificacion extends Model
{
    protected $table = 'notifications';

    protected $fillable = ['user_id', 'type', 'title', 'message', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
