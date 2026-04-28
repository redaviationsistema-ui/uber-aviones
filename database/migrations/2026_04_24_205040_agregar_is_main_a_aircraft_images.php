<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft_images', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft_images', 'is_main')) {
                $table->boolean('is_main')->default(false)->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aircraft_images', function (Blueprint $table) {
            if (Schema::hasColumn('aircraft_images', 'is_main')) {
                $table->dropColumn('is_main');
            }
        });
    }
};
