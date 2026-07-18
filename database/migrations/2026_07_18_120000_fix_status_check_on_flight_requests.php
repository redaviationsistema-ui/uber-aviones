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
    -- Drop the existing CHECK constraint on the status column
    SELECT con.conname
      INTO constraint_name
      FROM pg_constraint con
      INNER JOIN pg_class rel ON rel.oid = con.conrelid
      INNER JOIN pg_namespace nsp ON nsp.oid = con.connamespace
     WHERE rel.relname = 'flight_requests'
       AND con.contype = 'c'
       AND pg_get_constraintdef(con.oid) ILIKE '%status%';

    IF constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE flight_requests DROP CONSTRAINT %I', constraint_name);
    END IF;

    -- Add the new expanded CHECK constraint for the status column
    ALTER TABLE flight_requests
        ADD CONSTRAINT flight_requests_status_check
        CHECK (
            status IN (
                'pending',
                'matched',
                'quoted',
                'reserved',
                'cancelled',
                'expired',
                'draft',
                'borrador',
                'package_selected',
                'paquete elegido',
                'provider_pending',
                'proveedor por confirmar',
                'provider_accepted',
                'proveedor aceptado',
                'contract_pending',
                'contrato pendiente',
                'contract_signed',
                'contrato firmado',
                'payment_pending',
                'pago pendiente',
                'payment_confirmed',
                'pago confirmado',
                'flight_confirmed',
                'vuelo confirmado',
                'tracking_live',
                'tracking en vivo',
                'completed',
                'finalizada',
                'rejected',
                'rechazada'
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
ALTER TABLE flight_requests DROP CONSTRAINT IF EXISTS flight_requests_status_check;
SQL);
    }
};