<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider')->default('aws_rekognition');
            $table->string('template_type')->default('selfie-photo');
            $table->boolean('identity_verified')->default(false);
            $table->string('status')->default('pending');
            $table->decimal('face_confidence', 8, 2)->nullable();
            $table->decimal('face_match_score', 8, 2)->nullable();
            $table->decimal('liveness_score', 8, 2)->nullable();
            $table->decimal('brightness', 8, 2)->nullable();
            $table->decimal('sharpness', 8, 2)->nullable();
            $table->decimal('yaw', 8, 2)->nullable();
            $table->decimal('pitch', 8, 2)->nullable();
            $table->decimal('roll', 8, 2)->nullable();
            $table->boolean('face_occluded')->default(false);
            $table->string('image_path')->nullable();
            $table->string('aws_request_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
