<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aircraft_availability_blocks')) {
            return;
        }

        Schema::create('aircraft_availability_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->timestamp('start_datetime')->index();
            $table->timestamp('end_datetime')->index();
            $table->string('status', 30)->default('active')->index();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['aircraft_id', 'reservation_id'], 'aircraft_availability_blocks_aircraft_reservation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_availability_blocks');
    }
};
