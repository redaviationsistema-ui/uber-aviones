<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model
{
    protected $table = 'checklist_items';

    protected $fillable = [
        'checklist_id', 'code', 'category', 'label', 'status', 'is_required', 'is_critical',
        'notes', 'is_completed', 'completed_at', 'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'is_required' => 'boolean',
            'is_critical' => 'boolean',
        ];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(ChecklistOperacion::class, 'checklist_id');
    }
}
