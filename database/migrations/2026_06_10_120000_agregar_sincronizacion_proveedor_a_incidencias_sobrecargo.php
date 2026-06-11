<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_operation_incidents', function (Blueprint $table) {
            $table->string('phase', 30)->nullable()->after('priority');
            $table->foreignId('provider_timeline_id')
                ->nullable()
                ->after('reported_by')
                ->constrained('operation_timeline')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crew_operation_incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_timeline_id');
            $table->dropColumn('phase');
        });
    }
};
