<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flight_membership_plans')) {
            Schema::create('flight_membership_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 150)->unique();
                $table->text('description')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->string('currency', 10)->default('USD');
                $table->string('billing_interval', 20)->default('monthly')->index();
                $table->decimal('included_flights', 8, 2)->default(0);
                $table->decimal('included_hours', 8, 2)->default(0);
                $table->decimal('included_credit_amount', 12, 2)->default(0);
                $table->decimal('discount_percentage', 5, 2)->default(0);
                $table->boolean('rollover_flights')->default(false);
                $table->boolean('rollover_hours')->default(false);
                $table->boolean('rollover_credits')->default(false);
                $table->unsignedInteger('validity_days')->nullable();
                $table->boolean('auto_renew')->default(true);
                $table->boolean('is_active')->default(true)->index();
                $table->string('stripe_product_id', 255)->nullable()->index();
                $table->string('stripe_price_id', 255)->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('flight_memberships')) {
            Schema::create('flight_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained('flight_membership_plans')->restrictOnDelete();
                $table->string('status', 50)->default('pending_payment')->index();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('current_period_start')->nullable();
                $table->timestamp('current_period_end')->nullable();
                $table->string('stripe_customer_id', 255)->nullable()->index();
                $table->string('stripe_subscription_id', 255)->nullable()->unique();
                $table->string('stripe_checkout_session_id', 255)->nullable()->index();
                $table->string('last_invoice_id', 255)->nullable()->index();
                $table->timestamp('last_payment_at')->nullable();
                $table->boolean('cancel_at_period_end')->default(false);
                $table->timestamp('canceled_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('flight_membership_periods')) {
            Schema::create('flight_membership_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('flight_membership_id')->constrained('flight_memberships')->cascadeOnDelete();
                $table->string('membership_period_key', 120);
                $table->string('stripe_invoice_id', 255)->nullable();
                $table->timestamp('period_start');
                $table->timestamp('period_end');
                $table->string('status', 50)->default('active')->index();
                $table->decimal('granted_flights', 8, 2)->default(0);
                $table->decimal('granted_hours', 8, 2)->default(0);
                $table->decimal('granted_credit', 12, 2)->default(0);
                $table->decimal('used_flights', 8, 2)->default(0);
                $table->decimal('used_hours', 8, 2)->default(0);
                $table->decimal('used_credit', 12, 2)->default(0);
                $table->timestamps();

                $table->unique(['flight_membership_id', 'membership_period_key'], 'flight_membership_period_unique');
                $table->unique(['flight_membership_id', 'stripe_invoice_id'], 'flight_membership_invoice_unique');
            });
        }

        if (! Schema::hasTable('flight_membership_benefit_ledger')) {
            Schema::create('flight_membership_benefit_ledger', function (Blueprint $table) {
                $table->id();
                $table->foreignId('flight_membership_id')->constrained('flight_memberships')->cascadeOnDelete();
                $table->foreignId('flight_membership_period_id')->nullable()->constrained('flight_membership_periods')->nullOnDelete();
                $table->string('membership_period_key', 120)->index();
                $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
                $table->foreignId('flight_id')->nullable()->constrained('flight_requests')->nullOnDelete();
                $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
                $table->string('entry_type', 50)->index();
                $table->string('benefit_type', 50)->index();
                $table->decimal('quantity', 10, 2)->default(0);
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('status', 50)->default('posted')->index();
                $table->string('reference', 255)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->foreignId('reversed_entry_id')->nullable()->constrained('flight_membership_benefit_ledger')->nullOnDelete();
                $table->timestamps();

                $table->unique(['reversed_entry_id'], 'flight_membership_reversed_entry_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_membership_benefit_ledger');
        Schema::dropIfExists('flight_membership_periods');
        Schema::dropIfExists('flight_memberships');
        Schema::dropIfExists('flight_membership_plans');
    }
};
