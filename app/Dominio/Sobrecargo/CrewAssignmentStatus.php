<?php

namespace App\Dominio\Sobrecargo;

final class CrewAssignmentStatus
{
    public const PENDING_CONFIRMATION = 'pending_confirmation';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const PREPARATION_PENDING = 'preparation_pending';

    public const READY_FOR_OPERATION = 'ready_for_operation';

    public const CHECKED_IN = 'checked_in';

    public const PREFLIGHT_IN_PROGRESS = 'preflight_in_progress';

    public const CABIN_READY = 'cabin_ready';

    public const BOARDING = 'boarding';

    public const BOARDING_COMPLETED = 'boarding_completed';

    public const IN_FLIGHT = 'in_flight';

    public const LANDED = 'landed';

    public const POSTFLIGHT_PENDING = 'postflight_pending';

    public const REPORT_PENDING = 'report_pending';

    public const CREW_COMPLETED = 'crew_completed';

    public const ADMINISTRATIVELY_CLOSED = 'administratively_closed';

    public const NO_SHOW = 'no_show';

    public const TRANSITIONS = [
        self::PENDING_CONFIRMATION => [self::CONFIRMED, self::REJECTED, self::CANCELLED],
        self::CONFIRMED => [self::PREPARATION_PENDING, self::CANCELLED, self::NO_SHOW],
        self::PREPARATION_PENDING => [self::READY_FOR_OPERATION, self::CANCELLED],
        self::READY_FOR_OPERATION => [self::CHECKED_IN, self::NO_SHOW, self::CANCELLED],
        self::CHECKED_IN => [self::PREFLIGHT_IN_PROGRESS],
        self::PREFLIGHT_IN_PROGRESS => [self::CABIN_READY],
        self::CABIN_READY => [self::BOARDING],
        self::BOARDING => [self::BOARDING_COMPLETED],
        self::BOARDING_COMPLETED => [self::IN_FLIGHT],
        self::IN_FLIGHT => [self::LANDED],
        self::LANDED => [self::POSTFLIGHT_PENDING],
        self::POSTFLIGHT_PENDING => [self::REPORT_PENDING],
        self::REPORT_PENDING => [self::CREW_COMPLETED],
        self::CREW_COMPLETED => [self::ADMINISTRATIVELY_CLOSED],
    ];

    public static function normalize(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'asignado', 'assigned', 'pendiente', 'pending', 'pending_crew_response' => self::PENDING_CONFIRMATION,
            'aceptado', 'accepted', 'confirmado', 'confirmada', 'crew_confirmed' => self::CONFIRMED,
            'rechazado', 'rechazada', 'crew_declined' => self::REJECTED,
            'cancelada' => self::CANCELLED,
            'cabina_lista' => self::CABIN_READY,
            'pasajeros_recibidos' => self::BOARDING_COMPLETED,
            'in_progress', 'crew_active' => self::IN_FLIGHT,
            'completed', 'finalizada', 'finished' => self::CREW_COMPLETED,
            'closed', 'cerrada' => self::ADMINISTRATIVELY_CLOSED,
            '' => self::PENDING_CONFIRMATION,
            default => strtolower(trim((string) $status)),
        };
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[self::normalize($from)] ?? [], true);
    }
}
