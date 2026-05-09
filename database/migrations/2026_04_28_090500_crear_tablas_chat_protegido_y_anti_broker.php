<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('protected_chats')) {
            Schema::create('protected_chats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('flight_request_id')->constrained('flight_requests')->cascadeOnDelete();
                $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 50)->default('activo')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('chat_id')->constrained('protected_chats')->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->text('message');
                $table->text('sanitized_message')->nullable();
                $table->boolean('has_blocked_content')->default(false)->index();
                $table->string('blocked_reason', 120)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('anti_broker_flags')) {
            Schema::create('anti_broker_flags', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('flight_request_id')->nullable()->constrained('flight_requests')->nullOnDelete();
                $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
                $table->string('type', 100)->index();
                $table->string('detected_value')->nullable();
                $table->string('severity', 50)->default('media')->index();
                $table->string('status', 50)->default('abierta')->index();
                $table->timestamps();
            });
        }
    }
// DEMO
    public function down(): void
    {
        Schema::dropIfExists('anti_broker_flags');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('protected_chats');
    }
};
