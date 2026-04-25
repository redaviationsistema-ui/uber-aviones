<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flight_request_legs')) {
            return;
        }

        Schema::create('flight_request_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_request_id')->constrained('flight_requests')->cascadeOnDelete();
            $table->unsignedInteger('leg_order')->default(1);
            $table->string('origin', 20)->index();
            $table->string('destination', 20)->index();
            $table->timestamp('departure_datetime')->index();
            $table->timestamp('arrival_datetime')->nullable();
            $table->unsignedInteger('passengers')->default(1);
            $table->unsignedInteger('distance_km')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_request_legs');
    }
};
