<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aircraft_billing_payments')) {
            Schema::table('aircraft_billing_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('aircraft_billing_payments', 'provider_customer_id')) {
                    $table->string('provider_customer_id', 255)->nullable()->after('provider_payment_id')->index();
                }

                if (! Schema::hasColumn('aircraft_billing_payments', 'provider_invoice_id')) {
                    $table->string('provider_invoice_id', 255)->nullable()->after('provider_customer_id')->index();
                }
            });
        }

        if (Schema::hasTable('aircraft_subscriptions')) {
            Schema::table('aircraft_subscriptions', function (Blueprint $table) {
                if (! Schema::hasColumn('aircraft_subscriptions', 'provider_checkout_id')) {
                    $table->string('provider_checkout_id', 255)->nullable()->after('payment_reference')->index();
                }

                if (! Schema::hasColumn('aircraft_subscriptions', 'provider_subscription_id')) {
                    $table->string('provider_subscription_id', 255)->nullable()->after('provider_checkout_id')->index();
                }

                if (! Schema::hasColumn('aircraft_subscriptions', 'provider_customer_id')) {
                    $table->string('provider_customer_id', 255)->nullable()->after('provider_subscription_id')->index();
                }

                if (! Schema::hasColumn('aircraft_subscriptions', 'provider_invoice_id')) {
                    $table->string('provider_invoice_id', 255)->nullable()->after('provider_customer_id')->index();
                }

                if (! Schema::hasColumn('aircraft_subscriptions', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('provider_invoice_id')->index();
                }

                if (! Schema::hasColumn('aircraft_subscriptions', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('ends_at')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('aircraft_subscriptions')) {
            Schema::table('aircraft_subscriptions', function (Blueprint $table) {
                foreach ([
                    'provider_checkout_id',
                    'provider_subscription_id',
                    'provider_customer_id',
                    'provider_invoice_id',
                    'paid_at',
                    'cancelled_at',
                ] as $column) {
                    if (Schema::hasColumn('aircraft_subscriptions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('aircraft_billing_payments')) {
            Schema::table('aircraft_billing_payments', function (Blueprint $table) {
                foreach (['provider_customer_id', 'provider_invoice_id'] as $column) {
                    if (Schema::hasColumn('aircraft_billing_payments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
