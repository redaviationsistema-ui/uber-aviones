<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('airports')) {
            return;
        }

        Schema::table('airports', function (Blueprint $table) {
            if (! Schema::hasColumn('airports', 'climb_descent_adjustment_minutes')) {
                $table->integer('climb_descent_adjustment_minutes')->default(0)->after('type');
            }
        });

        DB::table('airports')
            ->whereIn('icao', ['MMTO', 'MMSD'])
            ->update(['climb_descent_adjustment_minutes' => 5]);

        DB::table('airports')
            ->where('icao', 'MMMX')
            ->update(['climb_descent_adjustment_minutes' => 10]);

        DB::table('airports')
            ->whereNull('climb_descent_adjustment_minutes')
            ->update(['climb_descent_adjustment_minutes' => 0]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('airports')) {
            return;
        }

        Schema::table('airports', function (Blueprint $table) {
            if (Schema::hasColumn('airports', 'climb_descent_adjustment_minutes')) {
                $table->dropColumn('climb_descent_adjustment_minutes');
            }
        });
    }
};
