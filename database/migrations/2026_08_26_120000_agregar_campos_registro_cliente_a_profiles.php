<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('profiles', 'client_type')) {
                $table->string('client_type', 40)->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('profiles', 'tax_id')) {
                $table->string('tax_id', 80)->nullable()->after('company_name');
            }

            if (! Schema::hasColumn('profiles', 'document_issuing_country')) {
                $table->string('document_issuing_country', 120)->nullable()->after('document_number');
            }

            if (! Schema::hasColumn('profiles', 'document_issue_date')) {
                $table->date('document_issue_date')->nullable()->after('document_issuing_country');
            }

            if (! Schema::hasColumn('profiles', 'document_status')) {
                $table->string('document_status', 80)->nullable()->after('document_expiration');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $columns = [
                'client_type',
                'tax_id',
                'document_issuing_country',
                'document_issue_date',
                'document_status',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
