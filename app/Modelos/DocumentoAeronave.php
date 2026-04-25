<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoAeronave extends Model
{
    protected $table = 'aircraft_documents';

    protected $fillable = [
        'aircraft_id',
        'type',
        'file_url',
        'document_type',
        'document_name',
        'document_url',
        'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class);
    }
}
