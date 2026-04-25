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
        'tax_data',
        'country',
        'city',
        'address',
        'avatar',
        'avatar_url',
    ];

    protected function casts(): array
    {
        return ['tax_data' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
