<?php
namespace App\Servicios\Reservas;
use Illuminate\Support\Facades\DB;
class CommercialHistoryGuard
{
    public static function user(int $id): void
    {
        abort_if(DB::table('flight_requests')->where('client_id', $id)->exists()
            || DB::table('providers')->where('user_id', $id)->exists()
            || DB::table('reservations')->where('client_id', $id)->exists(), 409,
            'El usuario tiene historial comercial. Desactiva su acceso para conservarlo.');
    }
    public static function aircraft(int $id): void
    {
        abort_if(DB::table('reservations')->where('aircraft_id', $id)->exists()
            || DB::table('quotes')->where('aircraft_id', $id)->exists()
            || DB::table('operations')->where('aircraft_id', $id)->exists(), 409,
            'La aeronave tiene historial comercial. Desactívala para conservarlo.');
    }
}
