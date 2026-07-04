<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            }
        });

        Schema::table('aircraft_availability_blocks', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft_availability_blocks', 'block_type')) {
                $table->string('block_type', 80)->default('reservation')->after('reservation_id');
            }

            if (! Schema::hasColumn('aircraft_availability_blocks', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
        });

        Schema::table('aircraft_availability_blocks', function (Blueprint $table) {
            foreach (['block_type', 'released_at'] as $column) {
                if (Schema::hasColumn('aircraft_availability_blocks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
