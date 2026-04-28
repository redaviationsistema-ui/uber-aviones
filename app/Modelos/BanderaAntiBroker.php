<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

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
}
