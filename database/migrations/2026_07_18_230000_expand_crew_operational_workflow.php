<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sobrecargo_assignments', function (Blueprint $table) {
            $table->string('role', 40)->default('flight_attendant');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('response_deadline')->nullable();
            $table->timestamp('presentation_time')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 80)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unique(['operation_id', 'sobrecargo_user_id'], 'uq_crew_assignment_operation_user');
            $table->index(['sobrecargo_user_id', 'status'], 'idx_crew_assignment_user_status');
            $table->index(['status', 'response_deadline'], 'idx_crew_assignment_status_deadline');
        });
        Schema::table('checklists', fn (Blueprint $table) => $table->timestamp('submitted_at')->nullable());
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->string('code', 100)->nullable();
            $table->string('category', 50)->default('general');
            $table->string('status', 30)->default('pending');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_critical')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['checklist_id', 'code'], 'uq_checklist_item_code');
            $table->index(['checklist_id', 'status'], 'idx_checklist_item_status');
        });
        Schema::table('operations', function (Blueprint $table) {
            $table->string('crew_checkin_status', 30)->nullable();
            $table->string('crew_checkin_base', 100)->nullable();
            $table->text('crew_checkin_notes')->nullable();
            $table->boolean('crew_fit_to_operate')->nullable();
            $table->timestamp('crew_landed_at')->nullable();
            $table->json('crew_final_report')->nullable();
            $table->timestamp('crew_report_submitted_at')->nullable();
            $table->timestamp('crew_administratively_closed_at')->nullable();
            $table->foreignId('crew_administratively_closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['sobrecargo_user_id', 'crew_status'], 'idx_operations_crew_status');
        });
        Schema::table('sobrecargo_disponibilidades', function (Blueprint $table) {
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();
            $table->string('tipo', 40)->nullable();
            $table->string('base', 100)->nullable();
            $table->boolean('inmediata')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sobrecargo_disponibilidades', fn (Blueprint $table) => $table->dropColumn(['hora_inicio', 'hora_fin', 'tipo', 'base', 'inmediata']));
        Schema::table('operations', function (Blueprint $table) {
            $table->dropIndex('idx_operations_crew_status');
            $table->dropConstrainedForeignId('crew_administratively_closed_by');
            $table->dropColumn(['crew_checkin_status', 'crew_checkin_base', 'crew_checkin_notes', 'crew_fit_to_operate', 'crew_landed_at', 'crew_final_report', 'crew_report_submitted_at', 'crew_administratively_closed_at']);
        });
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropUnique('uq_checklist_item_code');
            $table->dropIndex('idx_checklist_item_status');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['code', 'category', 'status', 'is_required', 'is_critical', 'notes']);
        });
        Schema::table('checklists', fn (Blueprint $table) => $table->dropColumn('submitted_at'));
        Schema::table('sobrecargo_assignments', function (Blueprint $table) {
            $table->dropUnique('uq_crew_assignment_operation_user');
            $table->dropIndex('idx_crew_assignment_user_status');
            $table->dropIndex('idx_crew_assignment_status_deadline');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['role', 'assigned_at', 'response_deadline', 'presentation_time', 'accepted_at', 'rejected_at', 'rejection_reason', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
