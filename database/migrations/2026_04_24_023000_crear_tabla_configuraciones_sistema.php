<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_settings')) {
            return;
        }

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->json('value')->nullable();
            $table->string('group', 100)->nullable()->default('general')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
