<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('type', 100)->index();
                $table->string('title', 150);
                $table->text('message')->nullable();
                $table->json('payload')->nullable();
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable()->index();
                $table->timestamps();
                $table->index(['provider_id', 'read_at', 'created_at'], 'notifications_provider_read_created_idx');
            });

            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'provider_id')) {
                $table->foreignId('provider_id')->nullable()->after('id')->constrained('providers')->nullOnDelete();
            }

            if (! Schema::hasColumn('notifications', 'payload')) {
                $table->json('payload')->nullable()->after('message');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'provider_id')) {
                $table->dropConstrainedForeignId('provider_id');
            }

            if (Schema::hasColumn('notifications', 'payload')) {
                $table->dropColumn('payload');
            }
        });
    }
};
