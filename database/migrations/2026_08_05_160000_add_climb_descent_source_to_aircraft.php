<?php

use App\Modelos\Aeronave;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'climb_descent_source')) {
                $table->string('climb_descent_source', 40)
                    ->default(Aeronave::CLIMB_DESCENT_SOURCE_LEGACY_UNKNOWN)
                    ->after('climb_descent_minutes');
            }
        });

        DB::table('aircraft')->update([
            'climb_descent_source' => DB::raw("
                CASE
                    WHEN category = 'Helicoptero' AND climb_descent_minutes = 15 THEN '".Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT."'
                    WHEN category = 'Turboprop' AND climb_descent_minutes = 25 THEN '".Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT."'
                    WHEN category = 'Light Jet' AND climb_descent_minutes = 30 THEN '".Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT."'
                    WHEN category = 'Mid Jet' AND climb_descent_minutes = 35 THEN '".Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT."'
                    WHEN category = 'Heavy Jet' AND climb_descent_minutes = 45 THEN '".Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT."'
                    ELSE '".Aeronave::CLIMB_DESCENT_SOURCE_LEGACY_UNKNOWN."'
                END
            "),
        ]);
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (Schema::hasColumn('aircraft', 'climb_descent_source')) {
                $table->dropColumn('climb_descent_source');
            }
        });
    }
};
