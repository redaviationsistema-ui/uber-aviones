<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservation_legs')) {
            return;
        }

        Schema::create('reservation_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->unsignedInteger('leg_order')->default(1);
            $table->string('origin', 20)->index();
            $table->string('destination', 20)->index();
            $table->timestamp('departure_datetime')->index();
            $table->timestamp('arrival_datetime')->nullable();
            $table->unsignedInteger('passengers')->default(1);
            $table->string('status')->default('scheduled')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_legs');
    }
};
