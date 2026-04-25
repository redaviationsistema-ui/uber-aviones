<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImagenAeronave extends Model
{
    protected $table = 'aircraft_images';

    protected $fillable = ['aircraft_id', 'image_url', 'sort_order', 'is_main'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean'];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class);
    }
}
