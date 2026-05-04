<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft_images', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft_images', 'kind')) {
                $table->string('kind', 50)->default('gallery')->after('aircraft_id');
            }

            if (! Schema::hasColumn('aircraft_images', 'title')) {
                $table->string('title', 150)->nullable()->after('kind');
            }

            if (! Schema::hasColumn('aircraft_images', 'visible_to_client')) {
                $table->boolean('visible_to_client')->default(true)->after('is_main');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aircraft_images', function (Blueprint $table) {
            if (Schema::hasColumn('aircraft_images', 'visible_to_client')) {
                $table->dropColumn('visible_to_client');
            }

            if (Schema::hasColumn('aircraft_images', 'title')) {
                $table->dropColumn('title');
            }

            if (Schema::hasColumn('aircraft_images', 'kind')) {
                $table->dropColumn('kind');
            }
        });
    }
};
