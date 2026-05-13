<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_requests', 'assigned_provider_id')) {
                $table->foreignId('assigned_provider_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('providers')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('flight_requests', 'assigned_aircraft_id')) {
                $table->foreignId('assigned_aircraft_id')
                    ->nullable()
                    ->after('assigned_provider_id')
                    ->constrained('aircraft')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            if (Schema::hasColumn('flight_requests', 'assigned_aircraft_id')) {
                $table->dropConstrainedForeignId('assigned_aircraft_id');
            }

            if (Schema::hasColumn('flight_requests', 'assigned_provider_id')) {
                $table->dropConstrainedForeignId('assigned_provider_id');
            }
        });
    }
};
