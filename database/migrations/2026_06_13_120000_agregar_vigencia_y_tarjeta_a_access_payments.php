<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'access_expires_at')) {
                    $table->timestamp('access_expires_at')->nullable()->after('paid_access_at')->index();
                }
            });
        }

        if (Schema::hasTable('access_payments')) {
            Schema::table('access_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('access_payments', 'billing_period_start')) {
                    $table->date('billing_period_start')->nullable()->after('currency')->index();
                }

                if (! Schema::hasColumn('access_payments', 'billing_period_end')) {
                    $table->date('billing_period_end')->nullable()->after('billing_period_start')->index();
                }

                if (! Schema::hasColumn('access_payments', 'card_brand')) {
                    $table->string('card_brand', 120)->nullable()->after('provider_checkout_id');
                }

                if (! Schema::hasColumn('access_payments', 'card_last4')) {
                    $table->string('card_last4', 4)->nullable()->after('card_brand');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'access_expires_at')) {
                    $table->dropColumn('access_expires_at');
                }
            });
        }

        if (Schema::hasTable('access_payments')) {
            Schema::table('access_payments', function (Blueprint $table) {
                foreach (['billing_period_start', 'billing_period_end', 'card_brand', 'card_last4'] as $column) {
                    if (Schema::hasColumn('access_payments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
