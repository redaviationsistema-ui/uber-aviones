<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImagenAeronave extends Model
{
    protected $table = 'aircraft_images';

    protected $fillable = ['aircraft_id', 'kind', 'title', 'image_url', 'sort_order', 'is_main', 'visible_to_client'];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'visible_to_client' => 'boolean',
        ];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class);
    }
}
