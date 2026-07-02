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
            if (! Schema::hasColumn('providers', 'operator_status')) {
                $table->string('operator_status', 50)->default('incomplete')->index();
            }
            if (! Schema::hasColumn('providers', 'access_enabled')) {
                $table->boolean('access_enabled')->default(false)->index();
            }
            if (! Schema::hasColumn('providers', 'validated_by')) {
                $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('providers', 'validated_at')) {
                $table->timestamp('validated_at')->nullable();
            }
            if (! Schema::hasColumn('providers', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('providers', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (! Schema::hasColumn('providers', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            if (! Schema::hasColumn('providers', 'changes_requested_by')) {
                $table->foreignId('changes_requested_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('providers', 'changes_requested_at')) {
                $table->timestamp('changes_requested_at')->nullable();
            }
            if (! Schema::hasColumn('providers', 'changes_notes')) {
                $table->text('changes_notes')->nullable();
            }
            if (! Schema::hasColumn('providers', 'admin_notes')) {
                $table->text('admin_notes')->nullable();
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
                'changes_requested_by',
                'rejected_by',
                'validated_by',
            ] as $foreignColumn) {
                if (Schema::hasColumn('providers', $foreignColumn)) {
                    $table->dropConstrainedForeignId($foreignColumn);
                }
            }

            foreach ([
                'operator_status',
                'access_enabled',
                'validated_at',
                'rejected_at',
                'rejection_reason',
                'changes_requested_at',
                'changes_notes',
                'admin_notes',
            ] as $column) {
                if (Schema::hasColumn('providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
