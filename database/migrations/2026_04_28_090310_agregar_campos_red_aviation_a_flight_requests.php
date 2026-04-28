<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_requests', 'workflow_status')) {
                $table->string('workflow_status', 50)->default('pendiente')->after('status')->index();
            }

            if (! Schema::hasColumn('flight_requests', 'aircraft_type')) {
                $table->string('aircraft_type', 100)->nullable()->after('passengers');
            }

            if (! Schema::hasColumn('flight_requests', 'requirements')) {
                $table->json('requirements')->nullable()->after('aircraft_type');
            }

            if (! Schema::hasColumn('flight_requests', 'package_snapshot')) {
                $table->json('package_snapshot')->nullable()->after('requirements');
            }

            if (! Schema::hasColumn('flight_requests', 'visibility_payload')) {
                $table->json('visibility_payload')->nullable()->after('package_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            if (Schema::hasColumn('flight_requests', 'workflow_status')) {
                $table->dropIndex(['workflow_status']);
            }

            foreach ([
                'visibility_payload',
                'package_snapshot',
                'requirements',
                'aircraft_type',
                'workflow_status',
            ] as $column) {
                if (Schema::hasColumn('flight_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
