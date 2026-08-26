<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'biometric_selfie_disk')) {
                $table->string('biometric_selfie_disk', 40)
                    ->nullable()
                    ->after('biometric_selfie_path');
            }

            if (! Schema::hasColumn('users', 'biometric_selfie_uploaded_at')) {
                $table->timestamp('biometric_selfie_uploaded_at')
                    ->nullable()
                    ->after('biometric_selfie_disk');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['biometric_selfie_disk', 'biometric_selfie_uploaded_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
