<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservations')) {
            return;
        }

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->foreignId('flight_request_id')->constrained('flight_requests')->cascadeOnDelete();
            $table->foreignId('quote_id')->unique()->constrained('quotes')->cascadeOnDelete();
            $table->string('reservation_code', 50)->unique();
            $table->enum('status', ['pending_payment', 'paid', 'confirmed', 'in_progress', 'completed', 'cancelled'])->default('pending_payment')->index();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
