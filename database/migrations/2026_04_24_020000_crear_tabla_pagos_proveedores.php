<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payouts')) {
            return;
        }

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->foreignId('commission_id')->nullable()->constrained('commissions')->nullOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('payment_method', 100)->nullable();
            $table->string('transaction_reference', 150)->nullable()->index();
            $table->enum('status', ['pending', 'released', 'processing', 'paid', 'failed', 'cancelled'])->default('pending')->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
