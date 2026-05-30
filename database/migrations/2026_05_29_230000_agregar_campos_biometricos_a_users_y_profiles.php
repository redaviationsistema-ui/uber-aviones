<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'identity_verification_status')) {
                $table->string('identity_verification_status', 60)->nullable()->after('status');
            }

            if (! Schema::hasColumn('users', 'identity_verification_message')) {
                $table->text('identity_verification_message')->nullable()->after('identity_verification_status');
            }

            if (! Schema::hasColumn('users', 'identity_verified')) {
                $table->boolean('identity_verified')->default(false)->after('identity_verification_message');
            }

            if (! Schema::hasColumn('users', 'face_detected')) {
                $table->boolean('face_detected')->default(false)->after('identity_verified');
            }

            if (! Schema::hasColumn('users', 'face_match_score')) {
                $table->decimal('face_match_score', 5, 2)->nullable()->after('face_detected');
            }

            if (! Schema::hasColumn('users', 'liveness_score')) {
                $table->decimal('liveness_score', 5, 2)->nullable()->after('face_match_score');
            }

            if (! Schema::hasColumn('users', 'image_storage_score')) {
                $table->decimal('image_storage_score', 5, 2)->nullable()->after('liveness_score');
            }

            if (! Schema::hasColumn('users', 'biometric_image_saved')) {
                $table->boolean('biometric_image_saved')->default(false)->after('image_storage_score');
            }

            if (! Schema::hasColumn('users', 'biometric_captured_at')) {
                $table->timestamp('biometric_captured_at')->nullable()->after('biometric_image_saved');
            }

            if (! Schema::hasColumn('users', 'biometric_provider')) {
                $table->string('biometric_provider', 80)->nullable()->after('biometric_captured_at');
            }

            if (! Schema::hasColumn('users', 'biometric_template_type')) {
                $table->string('biometric_template_type', 80)->nullable()->after('biometric_provider');
            }

            if (! Schema::hasColumn('users', 'biometric_selfie_path')) {
                $table->string('biometric_selfie_path')->nullable()->after('biometric_template_type');
            }
        });

        Schema::table('profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('profiles', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('business_type');
            }

            if (! Schema::hasColumn('profiles', 'nationality')) {
                $table->string('nationality', 120)->nullable()->after('birth_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [
                'identity_verification_status',
                'identity_verification_message',
                'identity_verified',
                'face_detected',
                'face_match_score',
                'liveness_score',
                'image_storage_score',
                'biometric_image_saved',
                'biometric_captured_at',
                'biometric_provider',
                'biometric_template_type',
                'biometric_selfie_path',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('profiles', function (Blueprint $table) {
            foreach (['birth_date', 'nationality'] as $column) {
                if (Schema::hasColumn('profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
