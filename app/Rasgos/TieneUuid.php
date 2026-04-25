<?php

namespace App\Rasgos;

use Illuminate\Support\Str;

trait TieneUuid
{
    protected static function bootTieneUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
