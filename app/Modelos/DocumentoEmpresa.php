<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoEmpresa extends Model
{
    protected $table = 'company_documents';

    protected $fillable = [
        'provider_id',
        'document_name',
        'original_name',
        'file_name',
        'file_url',
        'document_url',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'status',
        'notes',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'file_size_bytes' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }
}
