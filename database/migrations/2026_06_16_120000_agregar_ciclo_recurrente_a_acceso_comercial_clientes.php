<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'grace_period_ends_at')) {
                    $table->timestamp('grace_period_ends_at')->nullable()->after('access_expires_at')->index();
                }

                if (! Schema::hasColumn('users', 'next_retry_at')) {
                    $table->timestamp('next_retry_at')->nullable()->after('grace_period_ends_at')->index();
                }

                if (! Schema::hasColumn('users', 'provider_subscription_id')) {
                    $table->string('provider_subscription_id', 255)->nullable()->after('next_retry_at')->index();
                }

                if (! Schema::hasColumn('users', 'provider_customer_id')) {
                    $table->string('provider_customer_id', 255)->nullable()->after('provider_subscription_id')->index();
                }
            });
        }

        if (Schema::hasTable('access_payments')) {
            Schema::table('access_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('access_payments', 'provider_invoice_id')) {
                    $table->string('provider_invoice_id', 255)->nullable()->after('provider_payment_id')->index();
                }

                if (! Schema::hasColumn('access_payments', 'provider_subscription_id')) {
                    $table->string('provider_subscription_id', 255)->nullable()->after('provider_invoice_id')->index();
                }

                if (! Schema::hasColumn('access_payments', 'provider_customer_id')) {
                    $table->string('provider_customer_id', 255)->nullable()->after('provider_subscription_id')->index();
                }

                if (! Schema::hasColumn('access_payments', 'failure_reason')) {
                    $table->text('failure_reason')->nullable()->after('card_last4');
                }

                if (! Schema::hasColumn('access_payments', 'retry_count')) {
                    $table->unsignedInteger('retry_count')->default(0)->after('failure_reason');
                }

                if (! Schema::hasColumn('access_payments', 'grace_period_ends_at')) {
                    $table->timestamp('grace_period_ends_at')->nullable()->after('retry_count')->index();
                }
            });
        }

        if (Schema::hasTable('plans')) {
            DB::table('plans')
                ->where('code', 'client_access_one_time')
                ->update([
                    'billing_type' => 'recurring_access',
                    'interval_type' => 'monthly',
                    'billing_cycle' => 'monthly',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('access_payments')) {
            Schema::table('access_payments', function (Blueprint $table) {
                foreach ([
                    'provider_invoice_id',
                    'provider_subscription_id',
                    'provider_customer_id',
                    'failure_reason',
                    'retry_count',
                    'grace_period_ends_at',
                ] as $column) {
                    if (Schema::hasColumn('access_payments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                foreach ([
                    'grace_period_ends_at',
                    'next_retry_at',
                    'provider_subscription_id',
                    'provider_customer_id',
                ] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
