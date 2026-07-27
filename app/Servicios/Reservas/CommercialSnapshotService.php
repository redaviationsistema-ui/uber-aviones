<?php

namespace App\Servicios\Reservas;

use App\Modelos\Cotizacion;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use RuntimeException;

class CommercialSnapshotService
{
    public function build(
        Reserva $reservation,
        ?SolicitudVuelo $flightRequest = null,
        ?Cotizacion $quote = null,
    ): array {
        $flightRequest ??= $reservation->flightRequest;
        $quote ??= $reservation->quote;

        return [
            'version' => 1,
            'captured_at' => now()->toIso8601String(),
            'reservation_id' => $reservation->id,
            'flight_request_id' => $reservation->flight_request_id,
            'quote_id' => $reservation->quote_id,
            'client_id' => $reservation->client_id,
            'provider_id' => $reservation->provider_id,
            'aircraft_id' => $reservation->aircraft_id,
            'total_amount' => (string) $reservation->total_amount,
            'currency' => strtoupper((string) ($reservation->currency ?: 'USD')),
            'quote' => $quote ? [
                'id' => $quote->id,
                'total' => (string) $quote->total,
                'currency' => strtoupper((string) ($quote->currency ?: $reservation->currency ?: 'USD')),
            ] : null,
            'itinerary' => [
                'origin' => $flightRequest?->origin,
                'destination' => $flightRequest?->destination,
                'departure_date' => $flightRequest?->departure_date,
                'return_date' => $flightRequest?->return_date,
                'passengers' => $flightRequest?->passengers,
            ],
        ];
    }

    public function persistIfMissing(Reserva $reservation): Reserva
    {
        if (is_array($reservation->commercial_snapshot) && $reservation->commercial_snapshot !== []) {
            $this->assertIntegrity($reservation);

            return $reservation;
        }

        $snapshot = $this->build(
            $reservation->loadMissing(['flightRequest', 'quote']),
            $reservation->flightRequest,
            $reservation->quote,
        );
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $reservation->forceFill([
            'commercial_snapshot' => $snapshot,
            'commercial_snapshot_hash' => hash('sha256', $encoded),
        ])->save();

        return $reservation->refresh();
    }

    public function assertIntegrity(Reserva $reservation): void
    {
        $snapshot = $reservation->commercial_snapshot;
        $storedHash = trim((string) $reservation->commercial_snapshot_hash);
        if (! is_array($snapshot) || $snapshot === [] || $storedHash === '') {
            throw new RuntimeException('La reserva no tiene un snapshot comercial íntegro.');
        }

        $actualHash = hash('sha256', json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        if (! hash_equals($storedHash, $actualHash)) {
            throw new RuntimeException('El hash del snapshot comercial no coincide.');
        }
    }
}
