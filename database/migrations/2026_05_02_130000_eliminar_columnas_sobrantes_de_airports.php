<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('airports')) {
            return;
        }

        Schema::table('airports', function (Blueprint $table) {
            $columns = [];

            foreach (['icao_code', 'iata_code', 'created_at', 'updated_at'] as $column) {
                if (Schema::hasColumn('airports', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('airports')) {
            return;
        }

        Schema::table('airports', function (Blueprint $table) {
            if (! Schema::hasColumn('airports', 'icao_code')) {
                $table->string('icao_code', 10)->nullable()->index()->after('iata');
            }

            if (! Schema::hasColumn('airports', 'iata_code')) {
                $table->string('iata_code', 10)->nullable()->index()->after('icao_code');
            }

            if (! Schema::hasColumn('airports', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('airports', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }
};
