<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_requests', 'idempotency_key')) {
                $table->string('idempotency_key', 100)
                    ->nullable()
                    ->after('client_id');
            }
        });

        Schema::table('flight_requests', function (Blueprint $table) {
            $table->unique(['client_id', 'idempotency_key'], 'fr_client_idem_uq');
        });
    }

    public function down(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            $table->dropUnique('fr_client_idem_uq');

            if (Schema::hasColumn('flight_requests', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
