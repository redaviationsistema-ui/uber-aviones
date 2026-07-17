<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AircraftChecklistItem extends Model
{
    protected $table = 'aircraft_checklist_items';

    protected $fillable = [
        'checklist_id',
        'item_key',
        'label',
        'status',
        'notes',
    ];

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(AircraftChecklist::class, 'checklist_id');
    }
}
