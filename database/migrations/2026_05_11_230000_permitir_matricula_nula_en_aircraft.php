<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aircraft') || ! Schema::hasColumn('aircraft', 'registration')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE aircraft ALTER COLUMN registration TYPE VARCHAR(255)');
            DB::statement('ALTER TABLE aircraft ALTER COLUMN registration DROP NOT NULL');
            DB::statement("UPDATE aircraft SET registration = NULL WHERE registration IS NULL OR BTRIM(registration) = '' OR registration ~* '^PENDIENTE[0-9]*$'");

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE aircraft MODIFY registration VARCHAR(255) NULL');
            DB::statement("UPDATE aircraft SET registration = NULL WHERE registration IS NULL OR TRIM(registration) = '' OR registration REGEXP '^[Pp][Ee][Nn][Dd][Ii][Ee][Nn][Tt][Ee][0-9]*$'");

            return;
        }

        DB::table('aircraft')
            ->whereNull('registration')
            ->orWhere('registration', '')
            ->orWhere('registration', 'like', 'PENDIENTE%')
            ->update(['registration' => null]);
    }

    public function down(): void
    {
        // No se revierte a NOT NULL: producción puede tener aeronaves sin matrícula oficial todavía.
    }
};
