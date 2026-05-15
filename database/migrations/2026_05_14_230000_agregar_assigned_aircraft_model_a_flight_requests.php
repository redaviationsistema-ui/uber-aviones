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
            if (! Schema::hasColumn('flight_requests', 'assigned_aircraft_model')) {
                $table->string('assigned_aircraft_model', 150)
                    ->nullable()
                    ->after('assigned_aircraft_id');
            }
        });

        if (! Schema::hasTable('flight_requests') || ! Schema::hasTable('aircraft')) {
            return;
        }

        DB::table('flight_requests')
            ->whereNotNull('assigned_aircraft_id')
            ->orderBy('id')
            ->chunkById(100, function ($requests): void {
                $aircraftById = DB::table('aircraft')
                    ->whereIn('id', $requests->pluck('assigned_aircraft_id')->filter()->unique()->values())
                    ->pluck('model', 'id');

                foreach ($requests as $request) {
                    $model = $aircraftById[$request->assigned_aircraft_id] ?? null;

                    if ($model !== null) {
                        DB::table('flight_requests')
                            ->where('id', $request->id)
                            ->update(['assigned_aircraft_model' => $model]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            if (Schema::hasColumn('flight_requests', 'assigned_aircraft_model')) {
                $table->dropColumn('assigned_aircraft_model');
            }
        });
    }
};
