<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_matches', function (Blueprint $table) {
            if (! Schema::hasColumn('request_matches', 'estimated_price')) {
                $table->decimal('estimated_price', 12, 2)->nullable()->after('match_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('request_matches', function (Blueprint $table) {
            if (Schema::hasColumn('request_matches', 'estimated_price')) {
                $table->dropColumn('estimated_price');
            }
        });
    }
};
