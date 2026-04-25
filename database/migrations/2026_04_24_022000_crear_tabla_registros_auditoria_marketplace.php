<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 150)->index();
                $table->string('module', 100)->index();
                $table->text('description')->nullable();
                $table->string('ip_address', 100)->nullable();
                $table->text('user_agent')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->timestamps();
            });

            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('audit_logs', 'action')) {
                $table->string('action', 150)->nullable()->index();
            }
            if (! Schema::hasColumn('audit_logs', 'module')) {
                $table->string('module', 100)->nullable()->index();
            }
            if (! Schema::hasColumn('audit_logs', 'description')) {
                $table->text('description')->nullable();
            }
            if (! Schema::hasColumn('audit_logs', 'ip_address')) {
                $table->string('ip_address', 100)->nullable();
            }
            if (! Schema::hasColumn('audit_logs', 'user_agent')) {
                $table->text('user_agent')->nullable();
            }
            if (! Schema::hasColumn('audit_logs', 'old_values')) {
                $table->json('old_values')->nullable();
            }
            if (! Schema::hasColumn('audit_logs', 'new_values')) {
                $table->json('new_values')->nullable();
            }
        });
    }

    public function down(): void
    {
        // This table may be shared with the existing ERP module, so do not drop it.
    }
};
