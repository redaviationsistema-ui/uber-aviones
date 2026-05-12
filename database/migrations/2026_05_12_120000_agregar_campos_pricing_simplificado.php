<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            if (! Schema::hasColumn('providers', 'jet_a_price')) {
                $table->decimal('jet_a_price', 12, 2)->default(0)->after('commercial_name');
            }

            if (! Schema::hasColumn('providers', 'margin_percent')) {
                $table->decimal('margin_percent', 8, 4)->default(0.12)->after('jet_a_price');
            }

            if (! Schema::hasColumn('providers', 'fixed_fee')) {
                $table->decimal('fixed_fee', 12, 2)->default(0)->after('margin_percent');
            }
        });

        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'fuel_burn_gph')) {
                $table->decimal('fuel_burn_gph', 12, 2)->default(0)->after('operational_cost');
            }

            if (! Schema::hasColumn('aircraft', 'engine_reserve_rate')) {
                $table->decimal('engine_reserve_rate', 12, 2)->default(0)->after('fuel_burn_gph');
            }

            if (! Schema::hasColumn('aircraft', 'insurance_rate')) {
                $table->decimal('insurance_rate', 12, 2)->default(0)->after('engine_reserve_rate');
            }

            if (! Schema::hasColumn('aircraft', 'maintenance_rate')) {
                $table->decimal('maintenance_rate', 12, 2)->default(0)->after('insurance_rate');
            }

            if (! Schema::hasColumn('aircraft', 'crew_rate')) {
                $table->decimal('crew_rate', 12, 2)->default(0)->after('maintenance_rate');
            }

            if (! Schema::hasColumn('aircraft', 'repositioning_fee')) {
                $table->decimal('repositioning_fee', 12, 2)->default(0)->after('crew_rate');
            }

            if (! Schema::hasColumn('aircraft', 'overnight_fee')) {
                $table->decimal('overnight_fee', 12, 2)->default(0)->after('repositioning_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            foreach (['fixed_fee', 'margin_percent', 'jet_a_price'] as $column) {
                if (Schema::hasColumn('providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('aircraft', function (Blueprint $table) {
            foreach ([
                'overnight_fee',
                'repositioning_fee',
                'crew_rate',
                'maintenance_rate',
                'insurance_rate',
                'engine_reserve_rate',
                'fuel_burn_gph',
            ] as $column) {
                if (Schema::hasColumn('aircraft', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
