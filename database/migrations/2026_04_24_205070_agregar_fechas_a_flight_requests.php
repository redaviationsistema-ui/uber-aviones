<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_requests', 'departure_datetime')) {
                $table->timestamp('departure_datetime')->nullable()->after('destination');
            }

            if (! Schema::hasColumn('flight_requests', 'return_datetime')) {
                $table->timestamp('return_datetime')->nullable()->after('departure_datetime');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("update flight_requests set departure_datetime = (departure_date::text || ' ' || departure_time::text)::timestamp where departure_datetime is null and departure_date is not null and departure_time is not null");
            DB::statement('alter table flight_requests drop constraint if exists flight_requests_trip_type_check');
            DB::statement("alter table flight_requests add constraint flight_requests_trip_type_check check (trip_type in ('one_way', 'round_trip', 'multi_leg'))");
        }
    }

    public function down(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            foreach (['return_datetime', 'departure_datetime'] as $column) {
                if (Schema::hasColumn('flight_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
