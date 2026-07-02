<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('providers')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            if (! Schema::hasColumn('providers', 'provider_validation_requirements')) {
                $table->json('provider_validation_requirements')->nullable()->after('access_enabled');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('providers')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            if (Schema::hasColumn('providers', 'provider_validation_requirements')) {
                $table->dropColumn('provider_validation_requirements');
            }
        });
    }
};
