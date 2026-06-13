<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'user_type')) {
                $table->string('user_type', 50)->nullable()->after('role_target');
            }

            if (! Schema::hasColumn('plans', 'billing_type')) {
                $table->string('billing_type', 60)->nullable()->after('user_type');
            }

            if (! Schema::hasColumn('plans', 'amount')) {
                $table->decimal('amount', 12, 2)->default(0)->after('billing_type');
            }

            if (! Schema::hasColumn('plans', 'currency')) {
                $table->string('currency', 10)->default('USD')->after('amount');
            }

            if (! Schema::hasColumn('plans', 'interval_type')) {
                $table->string('interval_type', 50)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('plans', 'stripe_product_id')) {
                $table->string('stripe_product_id', 255)->nullable()->after('interval_type');
            }

            if (! Schema::hasColumn('plans', 'stripe_price_id')) {
                $table->string('stripe_price_id', 255)->nullable()->after('stripe_product_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'access_status')) {
                $table->string('access_status', 50)->default('trial_active')->after('status')->index();
            }

            if (! Schema::hasColumn('users', 'trial_started_at')) {
                $table->timestamp('trial_started_at')->nullable()->after('access_status');
            }

            if (! Schema::hasColumn('users', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable()->after('trial_started_at');
            }

            if (! Schema::hasColumn('users', 'free_quote_limit')) {
                $table->unsignedInteger('free_quote_limit')->default(1)->after('trial_ends_at');
            }

            if (! Schema::hasColumn('users', 'free_quotes_used')) {
                $table->unsignedInteger('free_quotes_used')->default(0)->after('free_quote_limit');
            }

            if (! Schema::hasColumn('users', 'has_paid_access')) {
                $table->boolean('has_paid_access')->default(false)->after('free_quotes_used');
            }

            if (! Schema::hasColumn('users', 'paid_access_at')) {
                $table->timestamp('paid_access_at')->nullable()->after('has_paid_access');
            }

            if (! Schema::hasColumn('users', 'access_payment_id')) {
                $table->unsignedBigInteger('access_payment_id')->nullable()->after('paid_access_at');
            }
        });

        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'billing_status')) {
                $table->string('billing_status', 50)->default('pending_payment')->after('status')->index();
            }

            if (! Schema::hasColumn('aircraft', 'billing_plan_id')) {
                $table->unsignedBigInteger('billing_plan_id')->nullable()->after('billing_status');
            }

            if (! Schema::hasColumn('aircraft', 'subscription_status')) {
                $table->string('subscription_status', 50)->default('inactive')->after('billing_plan_id')->index();
            }

            if (! Schema::hasColumn('aircraft', 'subscription_started_at')) {
                $table->timestamp('subscription_started_at')->nullable()->after('subscription_status');
            }

            if (! Schema::hasColumn('aircraft', 'subscription_ends_at')) {
                $table->timestamp('subscription_ends_at')->nullable()->after('subscription_started_at');
            }

            if (! Schema::hasColumn('aircraft', 'last_payment_at')) {
                $table->timestamp('last_payment_at')->nullable()->after('subscription_ends_at');
            }
        });

        if (! Schema::hasTable('access_payments')) {
            Schema::create('access_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('billing_plan_id')->constrained('plans')->restrictOnDelete();
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('currency', 10)->default('USD');
                $table->string('status', 50)->default('pending')->index();
                $table->string('provider', 50)->default('stripe');
                $table->string('provider_payment_id', 255)->nullable()->index();
                $table->string('provider_checkout_id', 255)->nullable()->index();
                $table->timestamp('paid_at')->nullable()->index();
                $table->json('gateway_response')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('aircraft_billing_payments')) {
            Schema::create('aircraft_billing_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
                $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
                $table->foreignId('billing_plan_id')->constrained('plans')->restrictOnDelete();
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('currency', 10)->default('USD');
                $table->date('billing_period_start')->nullable()->index();
                $table->date('billing_period_end')->nullable()->index();
                $table->string('status', 50)->default('pending')->index();
                $table->string('provider', 50)->default('stripe');
                $table->string('provider_payment_id', 255)->nullable()->index();
                $table->string('provider_subscription_id', 255)->nullable()->index();
                $table->string('provider_checkout_id', 255)->nullable()->index();
                $table->timestamp('paid_at')->nullable()->index();
                $table->json('gateway_response')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('plans')) {
            DB::table('plans')->updateOrInsert(
                ['code' => 'client_access_one_time'],
                [
                    'name' => 'Acceso unico cliente',
                    'slug' => 'client-access-one-time',
                    'description' => 'Acceso unico al sistema para cliente despues de su prueba inicial.',
                    'price' => 115.00,
                    'price_monthly' => 0,
                    'price_yearly' => 0,
                    'billing_cycle' => 'monthly',
                    'role_target' => 'client',
                    'user_type' => 'client',
                    'billing_type' => 'one_time',
                    'amount' => 115.00,
                    'currency' => 'USD',
                    'interval_type' => null,
                    'max_requests' => 0,
                    'max_aircraft' => 0,
                    'max_users' => 1,
                    'has_priority' => false,
                    'has_concierge' => false,
                    'has_reports' => false,
                    'is_enterprise' => false,
                    'is_active' => true,
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::table('plans')->updateOrInsert(
                ['code' => 'provider_aircraft_monthly'],
                [
                    'name' => 'Mensualidad por aeronave',
                    'slug' => 'provider-aircraft-monthly',
                    'description' => 'Cobro recurrente mensual por cada aeronave registrada por un proveedor.',
                    'price' => 100.00,
                    'price_monthly' => 100.00,
                    'price_yearly' => 0,
                    'billing_cycle' => 'monthly',
                    'role_target' => 'provider',
                    'user_type' => 'provider',
                    'billing_type' => 'recurring_per_aircraft',
                    'amount' => 100.00,
                    'currency' => 'USD',
                    'interval_type' => 'monthly',
                    'max_requests' => 0,
                    'max_aircraft' => 0,
                    'max_users' => 0,
                    'has_priority' => false,
                    'has_concierge' => false,
                    'has_reports' => false,
                    'is_enterprise' => false,
                    'is_active' => true,
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_billing_payments');
        Schema::dropIfExists('access_payments');

        Schema::table('aircraft', function (Blueprint $table) {
            foreach (['billing_status', 'billing_plan_id', 'subscription_status', 'subscription_started_at', 'subscription_ends_at', 'last_payment_at'] as $column) {
                if (Schema::hasColumn('aircraft', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['access_status', 'trial_started_at', 'trial_ends_at', 'free_quote_limit', 'free_quotes_used', 'has_paid_access', 'paid_access_at', 'access_payment_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('plans', function (Blueprint $table) {
            foreach (['user_type', 'billing_type', 'amount', 'currency', 'interval_type', 'stripe_product_id', 'stripe_price_id'] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
