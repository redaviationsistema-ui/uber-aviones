<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

class Aeropuerto extends Model
{
    protected $table = 'airports';

    public $timestamps = false;

    protected $fillable = [
        'iata',
        'icao',
        'name',
        'city',
        'country',
        'latitude',
        'longitude',
        'altitude',
        'utc_offset',
        'timezone',
        'type',
        'climb_descent_adjustment_minutes',
        'status',
    ];
}
