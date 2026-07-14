<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'admin_user_id')) {
                $table->foreignId('admin_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('audit_logs', 'entity')) {
                $table->string('entity', 150)->nullable()->after('module')->index();
            }
            if (! Schema::hasColumn('audit_logs', 'entity_id')) {
                $table->string('entity_id', 120)->nullable()->after('entity')->index();
            }
            if (! Schema::hasColumn('audit_logs', 'reason')) {
                $table->text('reason')->nullable()->after('description');
            }
            if (! Schema::hasColumn('audit_logs', 'result')) {
                $table->string('result', 60)->default('success')->after('reason')->index();
            }
            if (! Schema::hasColumn('audit_logs', 'before')) {
                $table->json('before')->nullable()->after('result');
            }
            if (! Schema::hasColumn('audit_logs', 'after')) {
                $table->json('after')->nullable()->after('before');
            }
            if (! Schema::hasColumn('audit_logs', 'metadata')) {
                $table->json('metadata')->nullable()->after('after');
            }
            if (! Schema::hasColumn('audit_logs', 'request_id')) {
                $table->string('request_id', 120)->nullable()->after('user_agent')->index();
            }
        });
    }

    public function down(): void
    {
    }
};
