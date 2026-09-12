<?php
namespace App\Servicios\Privacidad;

final class ClientPublicRepresentation
{
    public const PROVIDER_FIELDS = ['id', 'name', 'company_name', 'commercial_name'];
    public const AIRCRAFT_FIELDS = [
        'id', 'provider_id', 'model', 'manufacturer', 'category', 'model_year', 'registration',
        'capacity', 'base_airport', 'base_airport_id', 'base_airport_data', 'range_km', 'speed_kmh',
        'coverage', 'amenities', 'hourly_rate', 'airport_expenses_usd', 'minimum_hours', 'minimum_route_price',
        'climb_descent_minutes', 'climb_descent_source', 'repositioning_fee', 'overnight_fee', 'currency',
        'status', 'is_active', 'operational_status', 'images', 'image_url', 'main_image', 'main_image_url',
        'provider', 'name', 'type', 'photo', 'photos', 'image', 'seats', 'range', 'speed', 'description',
    ];

    public static function sanitize(array $value, string $key = ''): array
    {
        // A payment's scalar provider (e.g. stripe) never reaches this method.
        if (! array_is_list($value)) {
            if (in_array($key, ['provider', 'proveedor', 'operator', 'operador', 'assigned_provider'], true)
                || (isset($value['commercial_name'], $value['user_id'], $value['approval_status']))) {
                $value = array_intersect_key($value, array_flip(self::PROVIDER_FIELDS));
            } elseif (isset($value['model'], $value['registration']) && (isset($value['capacity']) || isset($value['provider_id']))) {
                $value = array_intersect_key($value, array_flip(self::AIRCRAFT_FIELDS));
            } elseif (in_array($key, ['sobrecargo', 'crew', 'captain', 'provider_user', 'operator_user', 'internal_user'], true)) {
                $value = array_intersect_key($value, array_flip(['id', 'name', 'avatar_url']));
            }
        }
        foreach ($value as $field => $item) {
            if (is_array($item)) $value[$field] = self::sanitize($item, is_string($field) ? $field : $key);
        }
        return $value;
    }
}
