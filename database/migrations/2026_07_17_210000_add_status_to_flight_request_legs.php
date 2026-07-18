<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_request_legs', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('passengers');
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::table('flight_request_legs', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};