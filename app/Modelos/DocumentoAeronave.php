<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoAeronave extends Model
{
    protected $table = 'aircraft_documents';

    protected $fillable = [
        'aircraft_id',
        'provider_id',
        'type',
        'file_url',
        'file_type',
        'thumbnail_url',
        'storage_disk',
        'storage_path',
        'thumbnail_path',
        'document_type',
        'document_name',
        'document_url',
        'expires_at',
        'status',
        'verified_by_admin',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_by_admin' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class);
    }
}

