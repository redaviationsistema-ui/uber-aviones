<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Plan;

class PlanControlador extends ControladorBase
{
    public function index()
    {
        return $this->ok([
            'plans' => Plan::query()
                ->where(function ($query) {
                    $query->where('status', 'active')->orWhere('is_active', true);
                })
                ->orderBy('price_monthly')
                ->get(),
        ]);
    }
}
