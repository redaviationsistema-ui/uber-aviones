<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aircraft_availability')) {
            return;
        }

        Schema::create('aircraft_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->timestamp('start_datetime')->index();
            $table->timestamp('end_datetime')->index();
            $table->enum('status', ['available', 'occupied', 'blocked', 'maintenance'])->default('available')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_availability');
    }
};
