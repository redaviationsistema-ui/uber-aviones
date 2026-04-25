<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionSistema extends Model
{
    protected $table = 'system_settings';

    protected $fillable = ['key', 'value', 'group'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
