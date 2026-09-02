<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class Perfil extends Model
{
    protected $table = 'profiles';

    protected $fillable = [
        'user_id',
        'client_type',
        'company_name',
        'business_type',
        'tax_id',
        'birth_date',
        'nationality',
        'document_type',
        'document_number',
        'document_issuing_country',
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
        'base_airport',
        'base_airport_id',
        'address',
        'avatar',
        'avatar_url',
    ];

    protected $appends = [
        'ine_front_url',
        'ine_back_url',
        'ine_front_download_url',
        'ine_back_download_url',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'identity_validation_required' => 'boolean',
            'tax_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function baseAirport(): BelongsTo
    {
        return $this->belongsTo(Aeropuerto::class, 'base_airport_id');
    }


    public function getIneFrontUrlAttribute(): ?string
    {
        return $this->resolveIdentityImageUrl('front');
    }

    public function getIneBackUrlAttribute(): ?string
    {
        return $this->resolveIdentityImageUrl('back');
    }

    public function getIneFrontDownloadUrlAttribute(): ?string
    {
        return $this->resolveIdentityImageUrl('front', true);
    }

    public function getIneBackDownloadUrlAttribute(): ?string
    {
        return $this->resolveIdentityImageUrl('back', true);
    }

    private function resolveIdentityImageUrl(string $side, bool $download = false): ?string
    {
        $path = $side === 'back' ? $this->ine_back_path : $this->ine_front_path;

        if (! $path || ! $this->user_id) {
            return null;
        }

        return URL::temporarySignedRoute(
            'public.identity-documents.show',
            now()->addMinutes(10),
            ['user' => $this->user_id, 'side' => $side, 'download' => $download ? 1 : null],
            absolute: false,
        );
    }
}
