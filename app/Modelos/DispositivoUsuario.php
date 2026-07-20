<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispositivoUsuario extends Model
{
    protected $table = 'user_devices';

    protected $fillable = ['user_id', 'device_uuid', 'push_token', 'platform', 'app_version', 'last_seen_at'];

    protected $hidden = ['push_token'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
