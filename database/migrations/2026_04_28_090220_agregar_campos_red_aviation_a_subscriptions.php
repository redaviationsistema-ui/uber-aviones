<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'trial_starts_at')) {
                $table->timestamp('trial_starts_at')->nullable()->after('plan_id');
            }

            if (! Schema::hasColumn('subscriptions', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable()->after('trial_starts_at');
            }

            if (! Schema::hasColumn('subscriptions', 'starts_at')) {
                $table->timestamp('starts_at')->nullable()->after('trial_ends_at');
            }

            if (! Schema::hasColumn('subscriptions', 'ends_at')) {
                $table->timestamp('ends_at')->nullable()->after('starts_at');
            }

            if (! Schema::hasColumn('subscriptions', 'renews_at')) {
                $table->timestamp('renews_at')->nullable()->after('ends_at');
            }

            if (! Schema::hasColumn('subscriptions', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('renews_at');
            }

            if (! Schema::hasColumn('subscriptions', 'payment_provider')) {
                $table->string('payment_provider', 100)->nullable()->after('cancelled_at');
            }

            if (! Schema::hasColumn('subscriptions', 'provider_subscription_id')) {
                $table->string('provider_subscription_id')->nullable()->after('payment_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            foreach ([
                'provider_subscription_id',
                'payment_provider',
                'cancelled_at',
                'renews_at',
                'ends_at',
                'starts_at',
                'trial_ends_at',
                'trial_starts_at',
            ] as $column) {
                if (Schema::hasColumn('subscriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
