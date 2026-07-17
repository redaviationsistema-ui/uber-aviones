<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AircraftChecklist extends Model
{
    protected $table = 'aircraft_checklists';

    protected $fillable = [
        'aircraft_id',
        'created_by',
        'updated_by',
    ];

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'aircraft_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AircraftChecklistItem::class, 'checklist_id');
    }
}
