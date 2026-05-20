<?php

namespace App\Http\Controladores;

use App\Modelos\Aeropuerto;
use Illuminate\Http\Request;
use Throwable;

class AeropuertoBusquedaControlador extends ControladorBase
{
    public function __invoke(Request $request)
    {
        $term = trim((string) ($request->query('q')
            ?: $request->query('search')
            ?: $request->query('term')));

        $limit = min(max((int) $request->query('limit', 6), 1), 20);

        $airports = collect();

        try {
            $airports = $this->searchWithDatabase($term, $limit);
        } catch (Throwable) {
            $airports = $this->searchWithSnapshot($term, $limit);
        }

        return $this->ok([
            'airports' => $airports,
        ]);
    }

    private function searchWithDatabase(string $term, int $limit)
    {
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

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (Aeropuerto $airport) => $this->mapAirport($airport->toArray()))
            ->values();
    }

    private function searchWithSnapshot(string $term, int $limit)
    {
        $snapshotPath = storage_path('app/airports-search-snapshot.json');

        if (! is_file($snapshotPath)) {
            return collect();
        }

        $airports = collect(json_decode((string) file_get_contents($snapshotPath), true) ?: []);

        if ($term === '') {
            return $airports
                ->sortBy(['city', 'icao'])
                ->take($limit)
                ->values();
        }

        $normalized = mb_strtolower($term);

        return $airports
            ->map(function (array $airport) use ($normalized) {
                $iata = mb_strtolower((string) ($airport['iata'] ?? ''));
                $icao = mb_strtolower((string) ($airport['icao'] ?? ''));
                $city = mb_strtolower((string) ($airport['city'] ?? ''));
                $name = mb_strtolower((string) ($airport['name'] ?? ''));
                $country = mb_strtolower((string) ($airport['country'] ?? ''));

                $rank = 99;

                foreach ([
                    $iata === $normalized,
                    $icao === $normalized,
                    $city === $normalized,
                    $name === $normalized,
                    str_starts_with($iata, $normalized),
                    str_starts_with($icao, $normalized),
                    str_starts_with($city, $normalized),
                    str_starts_with($name, $normalized),
                    str_contains($country, $normalized),
                    str_contains($iata, $normalized),
                    str_contains($icao, $normalized),
                    str_contains($city, $normalized),
                    str_contains($name, $normalized),
                ] as $index => $matched) {
                    if ($matched) {
                        $rank = $index + 1;
                        break;
                    }
                }

                return $rank < 99 ? ['rank' => $rank, 'airport' => $airport] : null;
            })
            ->filter()
            ->sortBy(['rank', fn (array $item) => $item['airport']['city'] ?? '', fn (array $item) => $item['airport']['icao'] ?? ''])
            ->take($limit)
            ->map(fn (array $item) => $item['airport'])
            ->values();
    }

    private function mapAirport(array $airport): array
    {
        return [
            'code' => $airport['icao'],
            'icao' => $airport['icao'],
            'iata' => $airport['iata'],
            'name' => $airport['name'],
            'city' => $airport['city'],
            'country' => $airport['country'],
            'climb_descent_adjustment_minutes' => (int) ($airport['climb_descent_adjustment_minutes'] ?? 0),
        ];
    }
}
