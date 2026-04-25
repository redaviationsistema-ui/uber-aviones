<?php

namespace App\Rasgos;

trait RespuestaApi
{
    protected function success(array $data = [], int $status = 200)
    {
        return response()->json(['success' => true] + $data, $status);
    }
}
