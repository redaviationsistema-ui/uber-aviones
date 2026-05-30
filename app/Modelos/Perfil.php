<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Perfil extends Model
{
    protected $table = 'profiles';

    protected $fillable = [
        'user_id',
        'company_name',
        'business_type',
        'birth_date',
        'nationality',
        'document_type',
        'document_number',
        'document_expiration',
        'identity_validation_required',
        'ine_curp',
        'ine_cic',
        'ine_ocr',
        'ine_scan_raw',
        'ine_scan_status',
        'ine_front_path',
        'ine_back_path',
        'tax_data',
        'country',
        'city',
        'address',
        'avatar',
        'avatar_url',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'document_expiration' => 'date',
            'identity_validation_required' => 'boolean',
            'tax_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
