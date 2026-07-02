<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_documents')) {
            return;
        }

        Schema::table('company_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('company_documents', 'original_name')) {
                $table->string('original_name', 255)->nullable()->after('document_name');
            }

            if (! Schema::hasColumn('company_documents', 'file_name')) {
                $table->string('file_name', 255)->nullable()->after('original_name');
            }

            if (! Schema::hasColumn('company_documents', 'storage_disk')) {
                $table->string('storage_disk', 50)->nullable()->after('document_url');
            }

            if (! Schema::hasColumn('company_documents', 'storage_path')) {
                $table->text('storage_path')->nullable()->after('storage_disk');
            }

            if (! Schema::hasColumn('company_documents', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('company_documents')) {
            return;
        }

        Schema::table('company_documents', function (Blueprint $table) {
            foreach (['notes', 'storage_path', 'storage_disk', 'file_name', 'original_name'] as $column) {
                if (Schema::hasColumn('company_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
