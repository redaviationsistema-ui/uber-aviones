<?php

namespace App\Http\Controladores;

use App\Modelos\Aeropuerto;
use Illuminate\Http\Request;

class AeropuertoBusquedaControlador extends ControladorBase
{
    public function __invoke(Request $request)
    {
        $term = trim((string) ($request->query('q')
            ?: $request->query('search')
            ?: $request->query('term')));

        $limit = min(max((int) $request->query('limit', 6), 1), 20);

        $query = Aeropuerto::query()
            ->select([
                'icao',
                'iata',
                'name',
                'city',
                'country',
                'climb_descent_adjustment_minutes',
            ])
            ->where('status', 'active');

        if ($term !== '') {
            $normalized = mb_strtolower($term);

            $query->where(function ($builder) use ($normalized) {
                $builder
                    ->orWhereRaw('LOWER(COALESCE(iata, \'\')) LIKE ?', ["{$normalized}%"])
                    ->orWhereRaw('LOWER(icao) LIKE ?', ["{$normalized}%"])
                    ->orWhereRaw('LOWER(COALESCE(city, \'\')) LIKE ?', ["{$normalized}%"])
                    ->orWhereRaw('LOWER(name) LIKE ?', ["{$normalized}%"])
                    ->orWhereRaw('LOWER(COALESCE(country, \'\')) LIKE ?', ["{$normalized}%"])
                    ->orWhereRaw('LOWER(COALESCE(iata, \'\')) LIKE ?', ["%{$normalized}%"])
                    ->orWhereRaw('LOWER(icao) LIKE ?', ["%{$normalized}%"])
                    ->orWhereRaw('LOWER(COALESCE(city, \'\')) LIKE ?', ["%{$normalized}%"])
                    ->orWhereRaw('LOWER(name) LIKE ?', ["%{$normalized}%"])
                    ->orWhereRaw('LOWER(COALESCE(country, \'\')) LIKE ?', ["%{$normalized}%"]);
            });

            $query->orderByRaw(
                "case
                    when lower(coalesce(iata, '')) = ? then 1
                    when lower(icao) = ? then 2
                    when lower(coalesce(city, '')) = ? then 3
                    when lower(name) = ? then 4
                    when lower(coalesce(iata, '')) like ? then 5
                    when lower(icao) like ? then 6
                    when lower(coalesce(city, '')) like ? then 7
                    when lower(name) like ? then 8
                    else 9
                end",
                [
                    $normalized,
                    $normalized,
                    $normalized,
                    $normalized,
                    "{$normalized}%",
                    "{$normalized}%",
                    "{$normalized}%",
                    "{$normalized}%",
                ],
            );
        } else {
            $query->orderBy('city')->orderBy('icao');
        }

        $airports = $query
            ->limit($limit)
            ->get()
            ->map(fn (Aeropuerto $airport) => [
                'code' => $airport->icao,
                'icao' => $airport->icao,
                'iata' => $airport->iata,
                'name' => $airport->name,
                'city' => $airport->city,
                'country' => $airport->country,
                'climb_descent_adjustment_minutes' => (int) ($airport->climb_descent_adjustment_minutes ?? 0),
            ])
            ->values();

        return $this->ok([
            'airports' => $airports,
        ]);
    }
}
