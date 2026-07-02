<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('providers')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            if (! Schema::hasColumn('providers', 'admin_validation_status')) {
                $table->string('admin_validation_status', 50)->default('expediente_incompleto')->index();
            }
            if (! Schema::hasColumn('providers', 'sat_validation_status')) {
                $table->string('sat_validation_status', 50)->default('pending')->index();
            }
            if (! Schema::hasColumn('providers', 'admin_validation_notes')) {
                $table->text('admin_validation_notes')->nullable();
            }
            if (! Schema::hasColumn('providers', 'admin_review_submitted_at')) {
                $table->timestamp('admin_review_submitted_at')->nullable();
            }
            if (! Schema::hasColumn('providers', 'admin_validated_by')) {
                $table->foreignId('admin_validated_by')->nullable()->after('admin_review_submitted_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('providers', 'admin_validated_at')) {
                $table->timestamp('admin_validated_at')->nullable();
            }
            if (! Schema::hasColumn('providers', 'admin_rejected_by')) {
                $table->foreignId('admin_rejected_by')->nullable()->after('admin_validated_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('providers', 'admin_rejected_at')) {
                $table->timestamp('admin_rejected_at')->nullable();
            }
            if (! Schema::hasColumn('providers', 'admin_changes_requested_by')) {
                $table->foreignId('admin_changes_requested_by')->nullable()->after('admin_rejected_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('providers', 'admin_changes_requested_at')) {
                $table->timestamp('admin_changes_requested_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('providers')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            foreach ([
                'admin_changes_requested_by',
                'admin_rejected_by',
                'admin_validated_by',
            ] as $foreignColumn) {
                if (Schema::hasColumn('providers', $foreignColumn)) {
                    $table->dropConstrainedForeignId($foreignColumn);
                }
            }

            foreach ([
                'admin_validation_status',
                'sat_validation_status',
                'admin_validation_notes',
                'admin_review_submitted_at',
                'admin_validated_at',
                'admin_rejected_at',
                'admin_changes_requested_at',
            ] as $column) {
                if (Schema::hasColumn('providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
