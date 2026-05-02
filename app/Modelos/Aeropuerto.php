<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

class Aeropuerto extends Model
{
    protected $table = 'airports';

    protected $fillable = [
        'iata',
        'icao',
        'iata_code',
        'icao_code',
        'name',
        'city',
        'country',
        'latitude',
        'longitude',
        'altitude',
        'utc_offset',
        'timezone',
        'type',
        'status',
    ];
}
