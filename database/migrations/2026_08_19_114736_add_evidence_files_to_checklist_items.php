<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            if (! Schema::hasColumn('checklist_items', 'evidence_files')) {
                $table->json('evidence_files')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            if (Schema::hasColumn('checklist_items', 'evidence_files')) {
                $table->dropColumn('evidence_files');
            }
        });
    }
};
