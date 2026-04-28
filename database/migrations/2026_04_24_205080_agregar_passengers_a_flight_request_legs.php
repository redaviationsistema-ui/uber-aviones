<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_request_legs', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_request_legs', 'passengers')) {
                $table->unsignedInteger('passengers')->default(1)->after('departure_datetime');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flight_request_legs', function (Blueprint $table) {
            if (Schema::hasColumn('flight_request_legs', 'passengers')) {
                $table->dropColumn('passengers');
            }
        });
    }
};
