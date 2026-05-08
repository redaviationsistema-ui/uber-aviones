<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'crew_status' => ! Schema::hasColumn('operations', 'crew_status'),
            'crew_confirmed_at' => ! Schema::hasColumn('operations', 'crew_confirmed_at'),
            'crew_decline_reason' => ! Schema::hasColumn('operations', 'crew_decline_reason'),
            'crew_notes' => ! Schema::hasColumn('operations', 'crew_notes'),
            'crew_checkin_at' => ! Schema::hasColumn('operations', 'crew_checkin_at'),
            'crew_service_started_at' => ! Schema::hasColumn('operations', 'crew_service_started_at'),
            'crew_service_completed_at' => ! Schema::hasColumn('operations', 'crew_service_completed_at'),
        ];

        if (! in_array(true, $columns, true)) {
            return;
        }

        Schema::table('operations', function (Blueprint $table) use ($columns) {
            if ($columns['crew_status']) {
                $table->string('crew_status')->nullable()->after('status');
            }
            if ($columns['crew_confirmed_at']) {
                $table->timestamp('crew_confirmed_at')->nullable()->after('crew_status');
            }
            if ($columns['crew_decline_reason']) {
                $table->string('crew_decline_reason')->nullable()->after('crew_confirmed_at');
            }
            if ($columns['crew_notes']) {
                $table->text('crew_notes')->nullable()->after('crew_decline_reason');
            }
            if ($columns['crew_checkin_at']) {
                $table->timestamp('crew_checkin_at')->nullable()->after('crew_notes');
            }
            if ($columns['crew_service_started_at']) {
                $table->timestamp('crew_service_started_at')->nullable()->after('crew_checkin_at');
            }
            if ($columns['crew_service_completed_at']) {
                $table->timestamp('crew_service_completed_at')->nullable()->after('crew_service_started_at');
            }
        });
    }

    public function down(): void
    {
        $existingColumns = array_values(array_filter([
            Schema::hasColumn('operations', 'crew_status') ? 'crew_status' : null,
            Schema::hasColumn('operations', 'crew_confirmed_at') ? 'crew_confirmed_at' : null,
            Schema::hasColumn('operations', 'crew_decline_reason') ? 'crew_decline_reason' : null,
            Schema::hasColumn('operations', 'crew_notes') ? 'crew_notes' : null,
            Schema::hasColumn('operations', 'crew_checkin_at') ? 'crew_checkin_at' : null,
            Schema::hasColumn('operations', 'crew_service_started_at') ? 'crew_service_started_at' : null,
            Schema::hasColumn('operations', 'crew_service_completed_at') ? 'crew_service_completed_at' : null,
        ]));

        if (! $existingColumns) {
            return;
        }

        Schema::table('operations', function (Blueprint $table) use ($existingColumns) {
            $table->dropColumn($existingColumns);
        });
    }
};
