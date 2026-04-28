<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatProtegido extends Model
{
    protected $table = 'protected_chats';

    protected $fillable = ['flight_request_id', 'client_id', 'provider_id', 'admin_id', 'status'];

    public function solicitudVuelo(): BelongsTo
    {
        return $this->belongsTo(SolicitudVuelo::class, 'flight_request_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(MensajeChat::class, 'chat_id');
    }
}
