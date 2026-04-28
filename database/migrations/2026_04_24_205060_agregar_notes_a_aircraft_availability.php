<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft_availability', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft_availability', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aircraft_availability', function (Blueprint $table) {
            if (Schema::hasColumn('aircraft_availability', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }
};
