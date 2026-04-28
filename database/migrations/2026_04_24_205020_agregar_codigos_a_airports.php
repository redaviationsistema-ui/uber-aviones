<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            if (! Schema::hasColumn('airports', 'icao_code')) {
                $table->string('icao_code', 10)->nullable()->after('id');
            }

            if (! Schema::hasColumn('airports', 'iata_code')) {
                $table->string('iata_code', 10)->nullable()->after('icao_code');
            }
        });

        DB::table('airports')->whereNull('icao_code')->whereNotNull('icao')->update(['icao_code' => DB::raw('icao')]);
        DB::table('airports')->whereNull('iata_code')->whereNotNull('iata')->update(['iata_code' => DB::raw('iata')]);
    }

    public function down(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            foreach (['iata_code', 'icao_code'] as $column) {
                if (Schema::hasColumn('airports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
