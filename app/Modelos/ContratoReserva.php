<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContratoReserva extends Model
{
    protected $table = 'reservation_contracts';

    protected $fillable = [
        'reservation_id',
        'signed_by_user_id',
        'contract_code',
        'status',
        'terms_snapshot',
        'document_url',
        'generated_at',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'terms_snapshot' => 'array',
            'generated_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reservation_id');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'signed_by_user_id');
    }
}
