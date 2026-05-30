<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DO $$
DECLARE
    constraint_name text;
BEGIN
    SELECT con.conname
      INTO constraint_name
      FROM pg_constraint con
      INNER JOIN pg_class rel ON rel.oid = con.conrelid
      INNER JOIN pg_namespace nsp ON nsp.oid = con.connamespace
     WHERE rel.relname = 'flight_requests'
       AND con.contype = 'c'
       AND pg_get_constraintdef(con.oid) ILIKE '%workflow_status%';

    IF constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE flight_requests DROP CONSTRAINT %I', constraint_name);
    END IF;

    ALTER TABLE flight_requests
        ADD CONSTRAINT flight_requests_workflow_status_check
        CHECK (
            workflow_status IS NULL OR workflow_status IN (
                'pendiente',
                'en_validacion',
                'buscando_operador',
                'operador_asignado',
                'aceptada',
                'rechazada',
                'sin_opciones_disponibles',
                'cotizada',
                'provider_pending',
                'provider_accepted',
                'contract_pending',
                'contract_signed',
                'payment_pending',
                'payment_confirmed',
                'flight_confirmed',
                'tracking_live',
                'completed',
                'cancelled',
                'expired',
                'reserva solicitada',
                'proveedor por confirmar',
                'proveedor aceptado',
                'contrato pendiente',
                'contrato firmado',
                'pago pendiente',
                'pago confirmado',
                'vuelo confirmado',
                'tracking en vivo',
                'finalizada',
                'cancelada',
                'expirada'
            )
        );
END
$$;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE flight_requests DROP CONSTRAINT IF EXISTS flight_requests_workflow_status_check;
SQL);
    }
};
