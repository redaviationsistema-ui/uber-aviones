<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'minimum_route_price')) {
                $table->decimal('minimum_route_price', 12, 2)->default(0)->after('minimum_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (Schema::hasColumn('aircraft', 'minimum_route_price')) {
                $table->dropColumn('minimum_route_price');
            }
        });
    }
};
