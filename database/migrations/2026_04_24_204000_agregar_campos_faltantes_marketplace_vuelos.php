<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_requests', 'request_code')) {
                $table->string('request_code')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('flight_requests', 'return_date')) {
                $table->date('return_date')->nullable()->after('trip_type');
            }

            if (! Schema::hasColumn('flight_requests', 'return_time')) {
                $table->time('return_time')->nullable()->after('return_date');
            }

            if (! Schema::hasColumn('flight_requests', 'estimated_distance_km')) {
                $table->unsignedInteger('estimated_distance_km')->nullable()->after('passengers');
            }
        });

        Schema::table('flight_request_legs', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_request_legs', 'arrival_datetime')) {
                $table->timestamp('arrival_datetime')->nullable()->after('departure_datetime');
            }

            if (! Schema::hasColumn('flight_request_legs', 'distance_km')) {
                $table->unsignedInteger('distance_km')->nullable()->after('arrival_datetime');
            }
        });

        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'quote_code')) {
                $table->string('quote_code')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('quotes', 'currency')) {
                $table->string('currency', 3)->default('USD')->after('total');
            }

            if (! Schema::hasColumn('quotes', 'provider_notes')) {
                $table->text('provider_notes')->nullable()->after('currency');
            }
        });

        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->index()->after('total_amount');
            }

            if (! Schema::hasColumn('reservations', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->index()->after('confirmed_at');
            }
        });

        Schema::table('reservation_legs', function (Blueprint $table) {
            if (! Schema::hasColumn('reservation_legs', 'arrival_datetime')) {
                $table->timestamp('arrival_datetime')->nullable()->after('departure_datetime');
            }

            if (! Schema::hasColumn('reservation_legs', 'status')) {
                $table->string('status')->default('scheduled')->index()->after('arrival_datetime');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'payment_method_id')) {
                $table->foreignId('payment_method_id')->nullable()->after('subscription_id')->constrained('payment_methods')->nullOnDelete();
            }

            if (! Schema::hasColumn('payments', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->index()->after('status');
            }

            if (! Schema::hasColumn('payments', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('paid_at');
            }

            if (! Schema::hasColumn('payments', 'gateway_response')) {
                $table->json('gateway_response')->nullable()->after('failure_reason');
            }
        });

        Schema::table('payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('payouts', 'transaction_reference')) {
                $table->string('transaction_reference')->nullable()->index()->after('currency');
            }

            if (! Schema::hasColumn('payouts', 'notes')) {
                $table->text('notes')->nullable()->after('released_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            foreach (['notes', 'transaction_reference'] as $column) {
                if (Schema::hasColumn('payouts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'payment_method_id')) {
                $table->dropConstrainedForeignId('payment_method_id');
            }

            foreach (['gateway_response', 'failure_reason', 'paid_at'] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('reservation_legs', function (Blueprint $table) {
            foreach (['status', 'arrival_datetime'] as $column) {
                if (Schema::hasColumn('reservation_legs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('reservations', function (Blueprint $table) {
            foreach (['cancelled_at', 'confirmed_at'] as $column) {
                if (Schema::hasColumn('reservations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('quotes', function (Blueprint $table) {
            foreach (['provider_notes', 'currency', 'quote_code'] as $column) {
                if (Schema::hasColumn('quotes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('flight_request_legs', function (Blueprint $table) {
            foreach (['distance_km', 'arrival_datetime'] as $column) {
                if (Schema::hasColumn('flight_request_legs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('flight_requests', function (Blueprint $table) {
            foreach (['estimated_distance_km', 'return_time', 'return_date', 'request_code'] as $column) {
                if (Schema::hasColumn('flight_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
