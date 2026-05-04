<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'provider_id')) {
                $table->foreignId('provider_id')->nullable()->after('operational_role')->constrained('providers')->nullOnDelete();
            }
        });

        DB::statement('
            UPDATE users
            SET provider_id = providers.id
            FROM providers
            WHERE providers.user_id = users.id
              AND users.provider_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'provider_id')) {
                $table->dropConstrainedForeignId('provider_id');
            }
        });
    }
};
