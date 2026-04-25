<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

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
}
