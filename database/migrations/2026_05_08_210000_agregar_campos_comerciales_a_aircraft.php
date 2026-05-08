<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'manufacturer')) {
                $table->string('manufacturer', 255)->nullable()->after('model');
            }

            if (! Schema::hasColumn('aircraft', 'model_year')) {
                $table->unsignedSmallInteger('model_year')->nullable()->after('manufacturer');
            }

            if (! Schema::hasColumn('aircraft', 'coverage')) {
                $table->string('coverage', 255)->nullable()->after('speed_kmh');
            }

            if (! Schema::hasColumn('aircraft', 'amenities')) {
                $table->text('amenities')->nullable()->after('coverage');
            }

            if (! Schema::hasColumn('aircraft', 'minimum_hours')) {
                $table->decimal('minimum_hours', 8, 2)->default(0)->after('hourly_rate');
            }

            if (! Schema::hasColumn('aircraft', 'operational_cost')) {
                $table->decimal('operational_cost', 12, 2)->default(0)->after('minimum_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            foreach ([
                'operational_cost',
                'minimum_hours',
                'amenities',
                'coverage',
                'model_year',
                'manufacturer',
            ] as $column) {
                if (Schema::hasColumn('aircraft', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
