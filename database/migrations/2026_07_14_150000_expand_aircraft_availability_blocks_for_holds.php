<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aircraft_availability_blocks')) {
            return;
        }

        Schema::table('aircraft_availability_blocks', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft_availability_blocks', 'quote_id')) {
                $table->foreignId('quote_id')->nullable()->after('aircraft_id')->constrained('quotes')->nullOnDelete();
            }

            if (! Schema::hasColumn('aircraft_availability_blocks', 'flight_request_id')) {
                $table->foreignId('flight_request_id')->nullable()->after('quote_id')->constrained('flight_requests')->nullOnDelete();
            }

            if (! Schema::hasColumn('aircraft_availability_blocks', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('flight_request_id')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('aircraft_availability_blocks', 'hold_expires_at')) {
                $table->timestamp('hold_expires_at')->nullable()->after('end_datetime')->index();
            }

            if (! Schema::hasColumn('aircraft_availability_blocks', 'payment_status')) {
                $table->string('payment_status', 50)->nullable()->after('hold_expires_at')->index();
            }

            if (! Schema::hasColumn('aircraft_availability_blocks', 'source')) {
                $table->string('source', 80)->nullable()->after('payment_status')->index();
            }

            if (! Schema::hasColumn('aircraft_availability_blocks', 'notes')) {
                $table->text('notes')->nullable()->after('reason');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('aircraft_availability_blocks')) {
            return;
        }

        Schema::table('aircraft_availability_blocks', function (Blueprint $table) {
            foreach (['quote_id', 'flight_request_id', 'user_id', 'hold_expires_at', 'payment_status', 'source', 'notes'] as $column) {
                if (Schema::hasColumn('aircraft_availability_blocks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
