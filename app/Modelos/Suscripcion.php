<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suscripcion extends Model
{
    protected $table = 'subscriptions';

    protected $fillable = ['user_id', 'plan_id', 'started_at', 'expires_at', 'status', 'payment_status'];

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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
