<?php

namespace App\Http\Controladores;

use App\Modelos\Plan;

class PlanControlador extends ControladorBase
{
    public function index()
    {
        return $this->ok([
            'plans' => Plan::where('status', 'active')->orderBy('price')->get(),
        ]);
    }
}
