<?php

namespace App\Http\Intermediarios;

use App\Servicios\RedAviation\AntiBrokerServicio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FiltroAntiBrokerIntermediario
{
    public function __construct(private readonly AntiBrokerServicio $antiBrokerServicio)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has('message')) {
            $request->attributes->set(
                'anti_broker_revision',
                $this->antiBrokerServicio->inspeccionar($request->input('message'))
            );
        }

        return $next($request);
    }
}
