<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('status')->index();
            }
        });

        DB::table('aircraft')
            ->whereNull('approved_at')
            ->where('status', 'active')
            ->update([
                'approved_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (Schema::hasColumn('aircraft', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
        });
    }
};
