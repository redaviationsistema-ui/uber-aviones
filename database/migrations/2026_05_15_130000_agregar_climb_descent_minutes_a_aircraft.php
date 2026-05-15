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
            if (! Schema::hasColumn('aircraft', 'climb_descent_minutes')) {
                $table->integer('climb_descent_minutes')->default(30)->after('minimum_route_price');
            }
        });

        DB::table('aircraft')->update([
            'climb_descent_minutes' => DB::raw("
                CASE
                    WHEN category = 'Helicoptero' THEN 15
                    WHEN category = 'Turboprop' THEN 25
                    WHEN category = 'Light Jet' THEN 30
                    WHEN category = 'Mid Jet' THEN 35
                    WHEN category = 'Heavy Jet' THEN 45
                    ELSE 30
                END
            "),
        ]);
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (Schema::hasColumn('aircraft', 'climb_descent_minutes')) {
                $table->dropColumn('climb_descent_minutes');
            }
        });
    }
};
