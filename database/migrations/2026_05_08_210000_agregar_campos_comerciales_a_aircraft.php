<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'manufacturer')) {
                $table->string('manufacturer', 255)->nullable();
            }

            if (! Schema::hasColumn('aircraft', 'model_year')) {
                $table->unsignedSmallInteger('model_year')->nullable();
            }

            if (! Schema::hasColumn('aircraft', 'coverage')) {
                $table->string('coverage', 255)->nullable();
            }

            if (! Schema::hasColumn('aircraft', 'amenities')) {
                $table->text('amenities')->nullable();
            }

            if (! Schema::hasColumn('aircraft', 'minimum_hours')) {
                $table->decimal('minimum_hours', 8, 2)->default(0);
            }

            if (! Schema::hasColumn('aircraft', 'operational_cost')) {
                $table->decimal('operational_cost', 12, 2)->default(0);
            }

        });

        if (Schema::hasColumn('aircraft', 'year') && Schema::hasColumn('aircraft', 'model_year')) {
            DB::statement('UPDATE aircraft SET model_year = COALESCE(model_year, year) WHERE year IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            $columnsToDrop = array_values(array_filter([
                'operational_cost',
                'minimum_hours',
                'amenities',
                'coverage',
                'model_year',
                'manufacturer',
            ], fn (string $column): bool => Schema::hasColumn('aircraft', $column)));

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
