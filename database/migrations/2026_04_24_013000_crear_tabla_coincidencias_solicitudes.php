<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('request_matches')) {
            return;
        }

        Schema::create('request_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_request_id')->constrained('flight_requests')->cascadeOnDelete();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->decimal('match_score', 5, 2)->default(0);
            $table->decimal('estimated_price', 12, 2)->nullable();
            $table->enum('status', ['pending', 'sent_to_provider', 'accepted', 'rejected', 'expired'])->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_matches');
    }
};
