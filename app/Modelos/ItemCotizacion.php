<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemCotizacion extends Model
{
    protected $table = 'quote_items';

    protected $fillable = ['quote_id', 'concept', 'description', 'quantity', 'unit_price', 'total'];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }
}
