<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BanderaAntiBroker extends Model
{
    protected $table = 'anti_broker_flags';

    protected $fillable = [
        'user_id',
        'flight_request_id',
        'message_id',
        'type',
        'detected_value',
        'severity',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(SolicitudVuelo::class, 'flight_request_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MensajeChat::class, 'message_id');
    }
}
