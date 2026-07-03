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
            if (! Schema::hasColumn('company_documents', 'document_type')) {
                $table->string('document_type', 100)->nullable()->after('document_name');
            }

            if (! Schema::hasColumn('company_documents', 'document_category')) {
                $table->string('document_category', 100)->nullable()->after('document_type');
            }

            if (! Schema::hasColumn('company_documents', 'document_slot')) {
                $table->string('document_slot', 100)->nullable()->after('document_category')->index();
            }

            if (! Schema::hasColumn('company_documents', 'document_section')) {
                $table->string('document_section', 100)->nullable()->after('document_slot');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('company_documents')) {
            return;
        }

        Schema::table('company_documents', function (Blueprint $table) {
            foreach (['document_section', 'document_slot', 'document_category', 'document_type'] as $column) {
                if (Schema::hasColumn('company_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
