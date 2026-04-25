<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webhook_events')) {
            Schema::create('webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 50)->index();
                $table->string('event_id')->nullable()->index();
                $table->string('event_type')->nullable()->index();
                $table->json('payload');
                $table->enum('status', ['received', 'processed', 'failed'])->default('received')->index();
                $table->text('error_message')->nullable();
                $table->timestamp('processed_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('login_attempts')) {
            Schema::create('login_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('email')->index();
                $table->string('ip_address', 45)->nullable()->index();
                $table->string('user_agent')->nullable();
                $table->boolean('successful')->default(false)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('verification_codes')) {
            Schema::create('verification_codes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type', 50)->index();
                $table->string('code_hash');
                $table->timestamp('expires_at')->index();
                $table->timestamp('used_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('attachments')) {
            Schema::create('attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->nullableMorphs('attachable');
                $table->string('disk')->default('public');
                $table->string('path');
                $table->string('original_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('favorite_aircraft')) {
            Schema::create('favorite_aircraft', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'aircraft_id']);
            });
        }

        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('subject');
                $table->enum('status', ['open', 'pending', 'resolved', 'closed'])->default('open')->index();
                $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('support_ticket_messages')) {
            Schema::create('support_ticket_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->text('message');
                $table->boolean('internal')->default(false)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('favorite_aircraft');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('verification_codes');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('webhook_events');
    }
};
