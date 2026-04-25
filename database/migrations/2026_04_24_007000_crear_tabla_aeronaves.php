<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aircraft')) {
            return;
        }

        Schema::create('aircraft', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->string('model', 150);
            $table->string('registration', 100)->unique();
            $table->unsignedInteger('capacity');
            $table->string('base_airport', 20)->index();
            $table->unsignedInteger('range_km')->nullable();
            $table->unsignedInteger('speed_kmh')->nullable();
            $table->decimal('hourly_rate', 12, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->enum('status', ['active', 'inactive', 'maintenance', 'blocked'])->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft');
    }
};
