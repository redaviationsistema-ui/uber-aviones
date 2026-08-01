<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aircraft_performance_profiles')) {
            return;
        }

        Schema::create('aircraft_performance_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->nullable()->constrained('aircraft')->nullOnDelete();
            $table->string('aircraft_model', 150)->nullable()->index();
            $table->string('aircraft_type', 100)->nullable()->index();
            $table->decimal('taxi_out_minutes', 8, 2)->default(0);
            $table->decimal('taxi_in_minutes', 8, 2)->default(0);
            $table->decimal('takeoff_minutes', 8, 2)->default(0);
            $table->decimal('landing_minutes', 8, 2)->default(0);
            $table->decimal('climb_minutes', 8, 2)->default(0);
            $table->decimal('climb_distance_nm', 8, 2)->default(0);
            $table->decimal('descent_minutes', 8, 2)->default(0);
            $table->decimal('descent_distance_nm', 8, 2)->default(0);
            $table->decimal('fixed_operational_minutes', 8, 2)->default(0);
            $table->decimal('short_leg_threshold_nm', 8, 2)->default(180);
            $table->decimal('medium_leg_threshold_nm', 8, 2)->default(500);
            $table->decimal('short_leg_speed_factor', 8, 4)->default(0.8);
            $table->decimal('medium_leg_speed_factor', 8, 4)->default(0.9);
            $table->decimal('long_leg_speed_factor', 8, 4)->default(1.0);
            $table->decimal('rounding_increment_minutes', 8, 2)->default(5);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['aircraft_id', 'is_active'], 'aircraft_perf_profiles_aircraft_active_idx');
            $table->index(['aircraft_model', 'is_active'], 'aircraft_perf_profiles_model_active_idx');
            $table->index(['aircraft_type', 'is_active'], 'aircraft_perf_profiles_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_performance_profiles');
    }
};
