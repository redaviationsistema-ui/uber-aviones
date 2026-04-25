<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('providers')) {
            return;
        }

        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name', 150);
            $table->string('commercial_name', 150)->nullable();
            $table->enum('approval_status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
