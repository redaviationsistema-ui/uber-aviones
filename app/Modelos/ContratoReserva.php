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
        'docusign_envelope_id',
        'docusign_status',
        'signer_name',
        'signer_email',
        'client_user_id',
        'contract_pdf_path',
        'signed_pdf_path',
        'generated_at',
        'sent_at',
        'signed_at',
        'completed_at',
        'last_webhook_payload',
    ];

    protected function casts(): array
    {
        return [
            'terms_snapshot' => 'array',
            'generated_at' => 'datetime',
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_webhook_payload' => 'array',
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
