<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_requests', 'base_price')) {
                $table->decimal('base_price', 12, 2)->nullable()->after('visibility_payload');
            }

            if (! Schema::hasColumn('flight_requests', 'operational_fee')) {
                $table->decimal('operational_fee', 12, 2)->nullable()->after('base_price');
            }

            if (! Schema::hasColumn('flight_requests', 'priority_price')) {
                $table->decimal('priority_price', 12, 2)->nullable()->after('operational_fee');
            }

            if (! Schema::hasColumn('flight_requests', 'final_price')) {
                $table->decimal('final_price', 12, 2)->nullable()->after('priority_price');
            }

            if (! Schema::hasColumn('flight_requests', 'currency')) {
                $table->string('currency', 10)->nullable()->after('final_price');
            }

            if (! Schema::hasColumn('flight_requests', 'pricing_formula_version')) {
                $table->string('pricing_formula_version', 120)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('flight_requests', 'pricing_context')) {
                $table->json('pricing_context')->nullable()->after('pricing_formula_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            foreach ([
                'pricing_context',
                'pricing_formula_version',
                'currency',
                'final_price',
                'priority_price',
                'operational_fee',
                'base_price',
            ] as $column) {
                if (Schema::hasColumn('flight_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
