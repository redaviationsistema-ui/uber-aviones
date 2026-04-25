<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aircraft_images')) {
            return;
        }

        Schema::create('aircraft_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->text('image_url');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_main')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_images');
    }
};
