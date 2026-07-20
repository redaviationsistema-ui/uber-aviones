<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('idempotency_key', 220)->nullable()->unique('uq_notifications_idempotency');
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('sobrecargo_assignments', function (Blueprint $table) {
            $table->dropForeign(['operation_id']);
            $table->foreign('operation_id')->references('id')->on('operations')->restrictOnDelete();
            $table->dropForeign(['sobrecargo_user_id']);
            $table->unsignedBigInteger('sobrecargo_user_id')->nullable()->change();
            $table->foreign('sobrecargo_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('checklists', function (Blueprint $table) {
            $table->dropForeign(['operation_id']);
            $table->foreign('operation_id')->references('id')->on('operations')->restrictOnDelete();
            $table->dropForeign(['sobrecargo_user_id']);
            $table->unsignedBigInteger('sobrecargo_user_id')->nullable()->change();
            $table->foreign('sobrecargo_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('crew_operation_incidents', function (Blueprint $table) {
            $table->dropForeign(['crew_operation_id']);
            $table->foreign('crew_operation_id')->references('id')->on('operations')->restrictOnDelete();
            $table->dropForeign(['crew_id']);
            $table->unsignedBigInteger('crew_id')->nullable()->change();
            $table->foreign('crew_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['module', 'action', 'created_at'], 'idx_audit_module_action_created');
            $table->index(['entity', 'entity_id', 'created_at'], 'idx_audit_entity_created');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_audit_module_action_created');
            $table->dropIndex('idx_audit_entity_created');
        });
        Schema::table('crew_operation_incidents', function (Blueprint $table) {
            $table->dropForeign(['crew_operation_id']);
            $table->foreign('crew_operation_id')->references('id')->on('operations')->cascadeOnDelete();
            $table->dropForeign(['crew_id']);
            $table->foreign('crew_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('checklists', function (Blueprint $table) {
            $table->dropForeign(['operation_id']);
            $table->foreign('operation_id')->references('id')->on('operations')->cascadeOnDelete();
            $table->dropForeign(['sobrecargo_user_id']);
            $table->foreign('sobrecargo_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('sobrecargo_assignments', function (Blueprint $table) {
            $table->dropForeign(['operation_id']);
            $table->foreign('operation_id')->references('id')->on('operations')->cascadeOnDelete();
            $table->dropForeign(['sobrecargo_user_id']);
            $table->foreign('sobrecargo_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->dropUnique('uq_notifications_idempotency');
            $table->dropColumn('idempotency_key');
        });
    }
};
