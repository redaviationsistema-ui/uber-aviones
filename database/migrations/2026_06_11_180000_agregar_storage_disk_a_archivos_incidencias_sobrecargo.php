<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_operation_incident_files', function (Blueprint $table) {
            if (! Schema::hasColumn('crew_operation_incident_files', 'storage_disk')) {
                $table->string('storage_disk', 50)->nullable()->after('incident_id');
            }
        });

        DB::table('crew_operation_incident_files')
            ->whereNull('storage_disk')
            ->update(['storage_disk' => 'public']);
    }

    public function down(): void
    {
        Schema::table('crew_operation_incident_files', function (Blueprint $table) {
            if (Schema::hasColumn('crew_operation_incident_files', 'storage_disk')) {
                $table->dropColumn('storage_disk');
            }
        });
    }
};
