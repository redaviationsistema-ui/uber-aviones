<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->enum('payment_type', ['subscription', 'reservation']);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('provider', 50)->nullable()->default('manual');
            $table->string('transaction_reference', 150)->nullable();
            $table->enum('status', ['pending', 'paid', 'failed', 'refunded', 'cancelled'])->default('pending')->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
