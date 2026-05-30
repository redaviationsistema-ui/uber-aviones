<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('profiles', 'document_type')) {
                $table->string('document_type', 50)->nullable()->after('nationality');
            }

            if (! Schema::hasColumn('profiles', 'document_number')) {
                $table->string('document_number', 120)->nullable()->after('document_type');
            }

            if (! Schema::hasColumn('profiles', 'document_expiration')) {
                $table->date('document_expiration')->nullable()->after('document_number');
            }

            if (! Schema::hasColumn('profiles', 'identity_validation_required')) {
                $table->boolean('identity_validation_required')->default(false)->after('document_expiration');
            }

            if (! Schema::hasColumn('profiles', 'ine_curp')) {
                $table->string('ine_curp', 32)->nullable()->after('identity_validation_required');
            }

            if (! Schema::hasColumn('profiles', 'ine_cic')) {
                $table->string('ine_cic', 64)->nullable()->after('ine_curp');
            }

            if (! Schema::hasColumn('profiles', 'ine_ocr')) {
                $table->string('ine_ocr', 64)->nullable()->after('ine_cic');
            }

            if (! Schema::hasColumn('profiles', 'ine_scan_raw')) {
                $table->text('ine_scan_raw')->nullable()->after('ine_ocr');
            }

            if (! Schema::hasColumn('profiles', 'ine_scan_status')) {
                $table->string('ine_scan_status', 40)->nullable()->after('ine_scan_raw');
            }

            if (! Schema::hasColumn('profiles', 'ine_front_path')) {
                $table->string('ine_front_path')->nullable()->after('ine_scan_status');
            }

            if (! Schema::hasColumn('profiles', 'ine_back_path')) {
                $table->string('ine_back_path')->nullable()->after('ine_front_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $columns = [
                'document_type',
                'document_number',
                'document_expiration',
                'identity_validation_required',
                'ine_curp',
                'ine_cic',
                'ine_ocr',
                'ine_scan_raw',
                'ine_scan_status',
                'ine_front_path',
                'ine_back_path',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
