<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MensajeChat extends Model
{
    protected $table = 'chat_messages';

    protected $fillable = ['chat_id', 'sender_id', 'message', 'sanitized_message', 'has_blocked_content', 'blocked_reason'];

    protected function casts(): array
    {
        return [
            'has_blocked_content' => 'boolean',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(ChatProtegido::class, 'chat_id');
    }
}
