<?php

namespace App\Servicios\Vuelos;

use App\Modelos\Aeronave;
use Illuminate\Support\Str;

final class ClimbDescentCategoryResolver
{
    private const CATEGORY_ALIASES = [
        'helicoptero' => 'Helicoptero',
        'helicopter' => 'Helicoptero',
        'turboprop' => 'Turboprop',
        'turbo prop' => 'Turboprop',
        'light jet' => 'Light Jet',
        'lightjet' => 'Light Jet',
        'mid jet' => 'Mid Jet',
        'midjet' => 'Mid Jet',
        'midsize jet' => 'Mid Jet',
        'super mid' => 'Mid Jet',
        'heavy jet' => 'Heavy Jet',
        'heavyjet' => 'Heavy Jet',
        'long range' => 'Heavy Jet',
        'ultra long' => 'Heavy Jet',
        'ultra long range' => 'Heavy Jet',
    ];

    public function normalizeCategoryKey(mixed $category): ?string
    {
        $normalized = $this->normalizeAlias($category);

        if ($normalized === '') {
            return null;
        }

        return self::CATEGORY_ALIASES[$normalized] ?? trim((string) $category);
    }

    public function resolveClimbDescentMinutesForCategory(?string $category): int
    {
        $defaults = $this->configuredDefaults();
        $categoryKey = $this->normalizeCategoryKey($category);

        if ($categoryKey !== null && array_key_exists($categoryKey, $defaults)) {
            return max((int) $defaults[$categoryKey], 0);
        }

        return $this->fallbackMinutes($defaults);
    }

    public function resolveAircraftMinutes(Aeronave $aircraft): int
    {
        $persistedMinutes = (int) ($aircraft->climb_descent_minutes ?? 0);

        if ($persistedMinutes > 0) {
            return $persistedMinutes;
        }

        return $this->resolveClimbDescentMinutesForCategory((string) ($aircraft->category ?? ''));
    }

    /**
     * @return array<string, int>
     */
    public function configuredDefaults(): array
    {
        $defaults = [];

        foreach ((array) config('vuelos.climb_descent_defaults', []) as $category => $minutes) {
            $officialKey = $this->normalizeCategoryKey((string) $category);

            if ($officialKey === null) {
                continue;
            }

            $defaults[$officialKey] = max((int) $minutes, 0);
        }

        return $defaults;
    }

    /**
     * @param  array<string, int>  $defaults
     */
    public function fallbackMinutes(array $defaults = []): int
    {
        $configuredFallback = (int) config('vuelos.climb_descent_default_fallback', 30);

        if ($configuredFallback > 0) {
            return $configuredFallback;
        }

        foreach ($defaults === [] ? $this->configuredDefaults() : $defaults as $minutes) {
            $minutes = (int) $minutes;

            if ($minutes > 0) {
                return $minutes;
            }
        }

        return 30;
    }

    private function normalizeAlias(mixed $category): string
    {
        $value = trim((string) ($category ?? ''));
        if ($value === '') {
            return '';
        }

        $ascii = Str::of($value)
            ->ascii()
            ->lower()
            ->replace('_', ' ')
            ->squish();

        return (string) $ascii;
    }
}
