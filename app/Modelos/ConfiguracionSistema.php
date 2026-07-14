<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class ConfiguracionSistema extends Model
{
    protected $table = 'system_settings';

    protected $fillable = ['key', 'value', 'group'];

    protected function value(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): mixed {
                if ($value === null || $value === '') {
                    return null;
                }

                if (is_array($value) || is_bool($value) || is_int($value) || is_float($value)) {
                    return $value;
                }

                $decoded = json_decode((string) $value, true);

                return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            },
            set: fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
