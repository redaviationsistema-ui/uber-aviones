<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('airports')) {
            return;
        }

        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->string('icao', 4)->unique();
            $table->string('iata', 3)->nullable()->index();
            $table->string('icao_code', 10)->nullable()->index();
            $table->string('iata_code', 10)->nullable()->index();
            $table->string('name', 180);
            $table->string('city', 120)->nullable();
            $table->string('country', 120)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
