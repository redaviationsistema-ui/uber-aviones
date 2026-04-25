<?php

namespace App\Servicios\Administracion;

use App\Modelos\ConfiguracionSistema;

class AdminSettingsServicio
{
    public function set(string $key, mixed $value, string $group = 'general')
    {
        return ConfiguracionSistema::updateOrCreate(['key' => $key], compact('value', 'group'));
    }
}
