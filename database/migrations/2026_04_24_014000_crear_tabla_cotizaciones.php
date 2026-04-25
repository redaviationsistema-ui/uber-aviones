<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quotes')) {
            return;
        }

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_code')->nullable()->unique();
            $table->foreignId('flight_request_id')->constrained('flight_requests')->cascadeOnDelete();
            $table->foreignId('aircraft_id')->nullable()->constrained('aircraft')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('taxes', 12, 2)->default(0);
            $table->decimal('fees', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->text('provider_notes')->nullable();
            $table->enum('status', ['pending', 'sent', 'accepted', 'rejected', 'expired', 'cancelled'])->default('pending')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
