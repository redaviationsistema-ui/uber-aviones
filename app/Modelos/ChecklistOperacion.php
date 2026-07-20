<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistOperacion extends Model
{
    protected $table = 'checklists';

    protected $fillable = ['operation_id', 'sobrecargo_user_id', 'type', 'status', 'submitted_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(Operacion::class, 'operation_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'checklist_id');
    }
}
