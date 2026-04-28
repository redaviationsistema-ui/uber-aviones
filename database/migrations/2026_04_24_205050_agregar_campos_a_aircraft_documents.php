<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft_documents', 'document_type')) {
                $table->string('document_type', 100)->nullable()->after('aircraft_id');
            }

            if (! Schema::hasColumn('aircraft_documents', 'document_name')) {
                $table->string('document_name', 150)->nullable()->after('document_type');
            }

            if (! Schema::hasColumn('aircraft_documents', 'document_url')) {
                $table->text('document_url')->nullable()->after('document_name');
            }
        });

        DB::table('aircraft_documents')->whereNull('document_type')->whereNotNull('type')->update(['document_type' => DB::raw('type')]);
        DB::table('aircraft_documents')->whereNull('document_url')->whereNotNull('file_url')->update(['document_url' => DB::raw('file_url')]);
    }

    public function down(): void
    {
        Schema::table('aircraft_documents', function (Blueprint $table) {
            foreach (['document_url', 'document_name', 'document_type'] as $column) {
                if (Schema::hasColumn('aircraft_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
