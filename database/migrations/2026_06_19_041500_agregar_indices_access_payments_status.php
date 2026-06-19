<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_payments')) {
            return;
        }

        Schema::table('access_payments', function (Blueprint $table) {
            $table->index(['user_id', 'id'], 'access_payments_user_id_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('access_payments')) {
            return;
        }

        Schema::table('access_payments', function (Blueprint $table) {
            $table->dropIndex('access_payments_user_id_id_index');
        });
    }
};
