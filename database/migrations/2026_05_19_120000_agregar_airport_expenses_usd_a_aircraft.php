<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'airport_expenses_usd')) {
                $table->decimal('airport_expenses_usd', 12, 2)->default(0)->after('hourly_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (Schema::hasColumn('aircraft', 'airport_expenses_usd')) {
                $table->dropColumn('airport_expenses_usd');
            }
        });
    }
};
