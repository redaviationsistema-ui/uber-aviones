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
        'file_url',
        'document_url',
        'mime_type',
        'file_size_bytes',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }
}
