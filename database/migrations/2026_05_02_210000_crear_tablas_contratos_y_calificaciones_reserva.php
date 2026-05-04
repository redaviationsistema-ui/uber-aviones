<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reservation_contracts')) {
            Schema::create('reservation_contracts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
                $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('contract_code', 60)->unique();
                $table->string('status', 40)->default('generated')->index();
                $table->json('terms_snapshot')->nullable();
                $table->text('document_url')->nullable();
                $table->timestamp('generated_at')->nullable()->index();
                $table->timestamp('signed_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('service_reviews')) {
            Schema::create('service_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedTinyInteger('rating');
                $table->text('comment')->nullable();
                $table->timestamp('submitted_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['reservation_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reviews');
        Schema::dropIfExists('reservation_contracts');
    }
};
