<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoProveedor extends Model
{
    protected $table = 'payouts';

    protected $fillable = ['provider_id', 'commission_id', 'amount', 'currency', 'payment_method', 'transaction_reference', 'status', 'released_at', 'paid_at', 'notes'];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Comision::class, 'commission_id');
    }
}
