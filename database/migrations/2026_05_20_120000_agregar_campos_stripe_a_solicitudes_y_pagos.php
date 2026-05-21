<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_requests', 'payment_method')) {
                $table->string('payment_method', 50)->nullable()->after('notes');
            }

            if (! Schema::hasColumn('flight_requests', 'payment_status')) {
                $table->string('payment_status', 50)->nullable()->after('payment_method')->index();
            }

            if (! Schema::hasColumn('flight_requests', 'stripe_checkout_session_id')) {
                $table->string('stripe_checkout_session_id', 255)->nullable()->after('payment_status');
            }

            if (! Schema::hasColumn('flight_requests', 'stripe_payment_intent_id')) {
                $table->string('stripe_payment_intent_id', 255)->nullable()->after('stripe_checkout_session_id');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'flight_request_id')) {
                $table->foreignId('flight_request_id')->nullable()->after('reservation_id')->constrained('flight_requests')->nullOnDelete();
            }

            if (! Schema::hasColumn('payments', 'stripe_checkout_session_id')) {
                $table->string('stripe_checkout_session_id', 255)->nullable()->after('transaction_reference');
            }

            if (! Schema::hasColumn('payments', 'stripe_payment_intent_id')) {
                $table->string('stripe_payment_intent_id', 255)->nullable()->after('stripe_checkout_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            foreach (['flight_request_id', 'stripe_checkout_session_id', 'stripe_payment_intent_id'] as $column) {
                if (! Schema::hasColumn('payments', $column)) {
                    continue;
                }

                if ($column === 'flight_request_id') {
                    $table->dropConstrainedForeignId($column);
                    continue;
                }

                $table->dropColumn($column);
            }
        });

        Schema::table('flight_requests', function (Blueprint $table) {
            if (Schema::hasColumn('flight_requests', 'payment_status')) {
                $table->dropIndex(['payment_status']);
            }

            $columns = [
                'payment_method',
                'payment_status',
                'stripe_checkout_session_id',
                'stripe_payment_intent_id',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('flight_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
