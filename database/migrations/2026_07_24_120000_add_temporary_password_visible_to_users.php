<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'temporary_password_visible')) {
                $table->string('temporary_password_visible')->nullable()->after('password');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'temporary_password_visible')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('temporary_password_visible');
        });
    }
};
