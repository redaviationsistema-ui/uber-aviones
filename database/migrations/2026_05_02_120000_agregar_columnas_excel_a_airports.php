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
            if (! Schema::hasColumn('airports', 'altitude')) {
                $table->integer('altitude')->nullable()->after('longitude');
            }

            if (! Schema::hasColumn('airports', 'utc_offset')) {
                $table->decimal('utc_offset', 4, 1)->nullable()->after('altitude');
            }

            if (! Schema::hasColumn('airports', 'timezone')) {
                $table->string('timezone', 100)->nullable()->after('utc_offset');
            }

            if (! Schema::hasColumn('airports', 'type')) {
                $table->string('type', 50)->nullable()->after('timezone');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('airports')) {
            return;
        }

        Schema::table('airports', function (Blueprint $table) {
            $columns = [];

            foreach (['altitude', 'utc_offset', 'timezone', 'type'] as $column) {
                if (Schema::hasColumn('airports', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
