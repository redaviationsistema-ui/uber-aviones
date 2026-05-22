<?php

namespace App\Proveedores;

use App\Modelos\Reserva;
use JsonException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProveedorServicioAplicacion extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::bind('reservation', function ($value) {
            $normalizedValue = $this->normalizeReservationIdentifier($value);

            $reservation = Reserva::query()
                ->where('id', $normalizedValue)
                ->orWhere('flight_request_id', $normalizedValue)
                ->latest('id')
                ->first();

            if ($reservation) {
                return $reservation;
            }

            throw (new ModelNotFoundException())->setModel(Reserva::class, [$normalizedValue]);
        });
    }

    private function normalizeReservationIdentifier(mixed $value): string
    {
        if ($value instanceof Reserva) {
            return (string) $value->getKey();
        }

        if (is_array($value)) {
            return $this->normalizeReservationIdentifier(
                $value['id'] ?? $value['reservation_id'] ?? $value['flight_request_id'] ?? ''
            );
        }

        if (is_object($value)) {
            return $this->normalizeReservationIdentifier(
                $value->id ?? $value->reservation_id ?? $value->flight_request_id ?? ''
            );
        }

        $normalizedValue = trim((string) $value);

        if ($normalizedValue === '') {
            return '';
        }

        if (str_starts_with($normalizedValue, '{') || str_starts_with($normalizedValue, '[')) {
            try {
                $decoded = json_decode($normalizedValue, true, 512, JSON_THROW_ON_ERROR);

                return $this->normalizeReservationIdentifier($decoded);
            } catch (JsonException) {
                return $normalizedValue;
            }
        }

        return $normalizedValue;
    }
}
