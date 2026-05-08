<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft_documents', 'provider_id')) {
                $table->foreignId('provider_id')->nullable()->after('aircraft_id')->constrained('providers')->nullOnDelete();
            }

            if (! Schema::hasColumn('aircraft_documents', 'file_type')) {
                $table->string('file_type', 120)->nullable()->after('file_url');
            }

            if (! Schema::hasColumn('aircraft_documents', 'thumbnail_url')) {
                $table->text('thumbnail_url')->nullable()->after('document_url');
            }

            if (! Schema::hasColumn('aircraft_documents', 'storage_disk')) {
                $table->string('storage_disk', 50)->nullable()->after('thumbnail_url');
            }

            if (! Schema::hasColumn('aircraft_documents', 'storage_path')) {
                $table->text('storage_path')->nullable()->after('storage_disk');
            }

            if (! Schema::hasColumn('aircraft_documents', 'thumbnail_path')) {
                $table->text('thumbnail_path')->nullable()->after('storage_path');
            }

            if (! Schema::hasColumn('aircraft_documents', 'status')) {
                $table->string('status', 50)->default('pending')->index()->after('expires_at');
            }

            if (! Schema::hasColumn('aircraft_documents', 'verified_by_admin')) {
                $table->boolean('verified_by_admin')->default(false)->after('status');
            }

            if (! Schema::hasColumn('aircraft_documents', 'notes')) {
                $table->text('notes')->nullable()->after('verified_by_admin');
            }

            if (! Schema::hasColumn('aircraft_documents', 'metadata')) {
                $table->json('metadata')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aircraft_documents', function (Blueprint $table) {
            foreach (['metadata', 'notes', 'verified_by_admin', 'status', 'thumbnail_path', 'storage_path', 'storage_disk', 'thumbnail_url', 'file_type'] as $column) {
                if (Schema::hasColumn('aircraft_documents', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('aircraft_documents', 'provider_id')) {
                $table->dropConstrainedForeignId('provider_id');
            }
        });
    }
};
