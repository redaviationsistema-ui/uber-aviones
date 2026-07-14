<?php

namespace App\Servicios\Administracion;

use App\Modelos\ConfiguracionSistema;
use App\Modelos\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdminSettingsServicio
{
    private const DEFINITIONS = [
        'commercial_margin_percentage' => ['group' => 'pricing', 'type' => 'number', 'min' => 0, 'max' => 100, 'default' => 12],
        'quote_expiration_minutes' => ['group' => 'quotes', 'type' => 'integer', 'min' => 5, 'max' => 4320, 'default' => 120],
        'aircraft_hold_minutes' => ['group' => 'availability', 'type' => 'integer', 'min' => 5, 'max' => 720, 'default' => 30],
        'subscription_grace_days' => ['group' => 'billing', 'type' => 'integer', 'min' => 0, 'max' => 60, 'default' => 3],
        'document_expiration_warning_days' => ['group' => 'documents', 'type' => 'integer', 'min' => 1, 'max' => 180, 'default' => 30],
        'notification_preferences' => ['group' => 'notifications', 'type' => 'array', 'default' => ['email' => true, 'dashboard' => true]],
        'support_contact' => ['group' => 'support', 'type' => 'object', 'default' => ['email' => 'soporte@redaviation.test']],
        'allowed_operational_limits' => ['group' => 'operations', 'type' => 'array', 'default' => ['max_passengers_override' => false]],
    ];

    public function listVisible(): array
    {
        $stored = ConfiguracionSistema::query()
            ->whereIn('key', array_keys(self::DEFINITIONS))
            ->get()
            ->keyBy('key');

        return collect(self::DEFINITIONS)
            ->map(function (array $definition, string $key) use ($stored) {
                $record = $stored->get($key);

                return [
                    'key' => $key,
                    'group' => $definition['group'],
                    'type' => $definition['type'],
                    'value' => $record?->value ?? $definition['default'] ?? null,
                ];
            })
            ->sortBy(['group', 'key'])
            ->values()
            ->all();
    }

    public function updateMany(array $settings, string $reason): array
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => ['El motivo es obligatorio para actualizar la configuración administrativa.'],
            ]);
        }

        $current = collect($this->listVisible())->keyBy('key');
        $changes = [];

        foreach ($settings as $setting) {
            $key = trim((string) ($setting['key'] ?? ''));
            $definition = self::DEFINITIONS[$key] ?? null;

            if (! $definition) {
                throw ValidationException::withMessages([
                    "settings.{$key}" => ['La clave de configuración no está permitida.'],
                ]);
            }

            $nextValue = $this->normalizeValue($setting['value'] ?? null, $definition, $key);
            $currentValue = $current->get($key)['value'] ?? null;

            if ($currentValue === $nextValue) {
                continue;
            }

            ConfiguracionSistema::query()->updateOrCreate(
                ['key' => $key],
                [
                    'group' => $definition['group'],
                    'value' => $nextValue,
                ],
            );

            $changes[] = [
                'key' => $key,
                'group' => $definition['group'],
                'before' => $currentValue,
                'after' => $nextValue,
            ];
        }

        return [
            'settings' => $this->listVisible(),
            'changes' => $changes,
        ];
    }

    public function definition(string $key): ?array
    {
        return self::DEFINITIONS[$key] ?? null;
    }

    private function normalizeValue(mixed $value, array $definition, string $key): mixed
    {
        return match ($definition['type']) {
            'integer' => $this->normalizeInteger($value, $definition, $key),
            'number' => $this->normalizeNumber($value, $definition, $key),
            'array' => $this->normalizeArray($value, $key),
            'object' => $this->normalizeObject($value, $key),
            default => throw ValidationException::withMessages([
                "settings.{$key}" => ['Tipo de configuración no soportado.'],
            ]),
        };
    }

    private function normalizeInteger(mixed $value, array $definition, string $key): int
    {
        if (! is_numeric($value) || (int) $value != $value) {
            throw ValidationException::withMessages(["settings.{$key}" => ['El valor debe ser un entero válido.']]);
        }

        $normalized = (int) $value;

        if ($normalized < $definition['min'] || $normalized > $definition['max']) {
            throw ValidationException::withMessages(["settings.{$key}" => ['El valor está fuera del rango permitido.']]);
        }

        return $normalized;
    }

    private function normalizeNumber(mixed $value, array $definition, string $key): float
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages(["settings.{$key}" => ['El valor debe ser numérico.']]);
        }

        $normalized = round((float) $value, 2);

        if ($normalized < $definition['min'] || $normalized > $definition['max']) {
            throw ValidationException::withMessages(["settings.{$key}" => ['El valor está fuera del rango permitido.']]);
        }

        return $normalized;
    }

    private function normalizeArray(mixed $value, string $key): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (! is_array($value)) {
            throw ValidationException::withMessages(["settings.{$key}" => ['El valor debe ser un arreglo JSON válido.']]);
        }

        return $value;
    }

    private function normalizeObject(mixed $value, string $key): array
    {
        $normalized = $this->normalizeArray($value, $key);

        if (array_is_list($normalized)) {
            throw ValidationException::withMessages(["settings.{$key}" => ['El valor debe ser un objeto asociativo.']]);
        }

        return $normalized;
    }
}
