<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profiles')) {
            return;
        }

        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name', 150)->nullable();
            $table->string('business_type', 100)->nullable();
            $table->json('tax_data')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('avatar')->nullable();
            $table->text('avatar_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
