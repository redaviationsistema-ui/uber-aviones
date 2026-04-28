<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'operational_role')) {
                $table->string('operational_role', 50)->nullable()->after('role')->index();
            }

            if (! Schema::hasColumn('users', 'contact_strikes')) {
                $table->unsignedInteger('contact_strikes')->default(0)->after('status');
            }

            if (! Schema::hasColumn('users', 'contact_blocked_until')) {
                $table->timestamp('contact_blocked_until')->nullable()->after('contact_strikes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'contact_blocked_until')) {
                $table->dropColumn('contact_blocked_until');
            }

            if (Schema::hasColumn('users', 'contact_strikes')) {
                $table->dropColumn('contact_strikes');
            }

            if (Schema::hasColumn('users', 'operational_role')) {
                $table->dropIndex(['operational_role']);
                $table->dropColumn('operational_role');
            }
        });
    }
};
