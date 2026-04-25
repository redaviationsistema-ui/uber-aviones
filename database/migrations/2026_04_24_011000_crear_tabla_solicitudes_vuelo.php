<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flight_requests')) {
            return;
        }

        Schema::create('flight_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_code')->nullable()->unique();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('origin', 20)->index();
            $table->string('destination', 20)->index();
            $table->timestamp('departure_datetime')->nullable()->index();
            $table->timestamp('return_datetime')->nullable()->index();
            $table->date('departure_date')->nullable()->index();
            $table->time('departure_time')->nullable();
            $table->date('return_date')->nullable();
            $table->time('return_time')->nullable();
            $table->unsignedInteger('passengers');
            $table->unsignedInteger('estimated_distance_km')->nullable();
            $table->enum('trip_type', ['one_way', 'round_trip', 'multi_leg'])->default('one_way');
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'matched', 'quoted', 'reserved', 'cancelled', 'expired'])->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_requests');
    }
};
