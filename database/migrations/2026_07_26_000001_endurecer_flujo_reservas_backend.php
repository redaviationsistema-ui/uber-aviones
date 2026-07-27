<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('idempotency_keys')) {
            Schema::create('idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('operation', 80);
                $table->string('idempotency_key', 160);
                $table->char('request_hash', 64);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->json('response_body')->nullable();
                $table->string('resource_type', 80)->nullable();
                $table->unsignedBigInteger('resource_id')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'operation', 'idempotency_key'], 'idempotency_keys_scope_unique');
                $table->index(['resource_type', 'resource_id']);
            });
        }

        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                if (! Schema::hasColumn('reservations', 'commercial_snapshot')) {
                    $table->json('commercial_snapshot')->nullable()->after('currency');
                }
                if (! Schema::hasColumn('reservations', 'commercial_snapshot_hash')) {
                    $table->char('commercial_snapshot_hash', 64)->nullable()->index()->after('commercial_snapshot');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                if (Schema::hasColumn('reservations', 'commercial_snapshot_hash')) {
                    $table->dropIndex('reservations_commercial_snapshot_hash_index');
                    $table->dropColumn('commercial_snapshot_hash');
                }
                if (Schema::hasColumn('reservations', 'commercial_snapshot')) {
                    $table->dropColumn('commercial_snapshot');
                }
            });
        }

        Schema::dropIfExists('idempotency_keys');
    }
};
