<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetodoPago extends Model
{
    protected $table = 'payment_methods';

    protected $fillable = [
        'user_id',
        'type',
        'brand',
        'last_four',
        'provider',
        'provider_payment_method_id',
        'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
