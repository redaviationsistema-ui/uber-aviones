<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->unique('flight_request_id', 'reservations_flight_request_unique');
            });
        }

        if (Schema::hasTable('reservation_contracts')) {
            Schema::table('reservation_contracts', function (Blueprint $table) {
                $table->unique('reservation_id', 'reservation_contracts_reservation_unique');
                $table->unique('docusign_envelope_id', 'reservation_contracts_docusign_envelope_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('reservation_contracts')) {
            Schema::table('reservation_contracts', function (Blueprint $table) {
                $table->dropUnique('reservation_contracts_docusign_envelope_unique');
                $table->dropUnique('reservation_contracts_reservation_unique');
            });
        }

        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->dropUnique('reservations_flight_request_unique');
            });
        }
    }
};