<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'security_filter')) {
                $table->string('security_filter', 50)->nullable()->after('status');
            }

            if (! Schema::hasColumn('aircraft', 'security_score')) {
                $table->unsignedSmallInteger('security_score')->nullable()->after('security_filter');
            }

            if (! Schema::hasColumn('aircraft', 'airworthiness_status')) {
                $table->string('airworthiness_status', 100)->nullable()->after('security_score');
            }

            if (! Schema::hasColumn('aircraft', 'last_maintenance_at')) {
                $table->date('last_maintenance_at')->nullable()->after('airworthiness_status');
            }

            if (! Schema::hasColumn('aircraft', 'engine_run_at')) {
                $table->date('engine_run_at')->nullable()->after('last_maintenance_at');
            }

            if (! Schema::hasColumn('aircraft', 'captain_training_at')) {
                $table->date('captain_training_at')->nullable()->after('engine_run_at');
            }

            if (! Schema::hasColumn('aircraft', 'lodging_location')) {
                $table->string('lodging_location', 150)->nullable()->after('captain_training_at');
            }

            if (! Schema::hasColumn('aircraft', 'client_fbo')) {
                $table->string('client_fbo', 120)->nullable()->after('lodging_location');
            }

            if (! Schema::hasColumn('aircraft', 'dispatch_center')) {
                $table->string('dispatch_center', 120)->nullable()->after('client_fbo');
            }

            if (! Schema::hasColumn('aircraft', 'dispatch_notes')) {
                $table->text('dispatch_notes')->nullable()->after('dispatch_center');
            }

            if (! Schema::hasColumn('aircraft', 'security_notes')) {
                $table->text('security_notes')->nullable()->after('dispatch_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('aircraft', function (Blueprint $table) {
            foreach ([
                'security_notes',
                'dispatch_notes',
                'dispatch_center',
                'client_fbo',
                'lodging_location',
                'captain_training_at',
                'engine_run_at',
                'last_maintenance_at',
                'airworthiness_status',
                'security_score',
                'security_filter',
            ] as $column) {
                if (Schema::hasColumn('aircraft', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
