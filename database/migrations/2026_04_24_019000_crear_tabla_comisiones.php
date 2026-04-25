<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commissions')) {
            return;
        }

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->decimal('provider_amount', 12, 2)->default(0);
            $table->enum('status', ['held', 'released', 'cancelled'])->default('held')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
