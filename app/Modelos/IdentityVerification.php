<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityVerification extends Model
{
    protected $table = 'identity_verifications';

    protected $fillable = [
        'user_id',
        'provider',
        'template_type',
        'identity_verified',
        'status',
        'face_confidence',
        'face_match_score',
        'liveness_score',
        'brightness',
        'sharpness',
        'yaw',
        'pitch',
        'roll',
        'face_occluded',
        'image_path',
        'aws_request_id',
    ];

    protected function casts(): array
    {
        return [
            'identity_verified' => 'boolean',
            'face_confidence' => 'decimal:2',
            'face_match_score' => 'decimal:2',
            'liveness_score' => 'decimal:2',
            'brightness' => 'decimal:2',
            'sharpness' => 'decimal:2',
            'yaw' => 'decimal:2',
            'pitch' => 'decimal:2',
            'roll' => 'decimal:2',
            'face_occluded' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
