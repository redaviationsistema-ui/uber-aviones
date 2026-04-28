<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'currency')) {
                $table->string('currency', 10)->default('USD')->after('hourly_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (Schema::hasColumn('aircraft', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
