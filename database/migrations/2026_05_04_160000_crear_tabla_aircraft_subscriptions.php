<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aircraft_subscriptions')) {
            return;
        }

        Schema::create('aircraft_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('active')->index();
            $table->string('payment_provider', 100)->nullable();
            $table->string('payment_reference', 191)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['aircraft_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_subscriptions');
    }
};
