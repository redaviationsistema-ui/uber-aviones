<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'code')) {
                $table->string('code', 100)->nullable()->after('name');
            }

            if (! Schema::hasColumn('plans', 'description')) {
                $table->text('description')->nullable()->after('code');
            }

            if (! Schema::hasColumn('plans', 'price_monthly')) {
                $table->decimal('price_monthly', 12, 2)->default(0)->after('price');
            }

            if (! Schema::hasColumn('plans', 'price_yearly')) {
                $table->decimal('price_yearly', 12, 2)->default(0)->after('price_monthly');
            }

            if (! Schema::hasColumn('plans', 'role_target')) {
                $table->string('role_target', 50)->nullable()->after('billing_cycle');
            }

            if (! Schema::hasColumn('plans', 'max_requests')) {
                $table->unsignedInteger('max_requests')->default(0)->after('role_target');
            }

            if (! Schema::hasColumn('plans', 'max_aircraft')) {
                $table->unsignedInteger('max_aircraft')->default(0)->after('max_requests');
            }

            if (! Schema::hasColumn('plans', 'max_users')) {
                $table->unsignedInteger('max_users')->default(1)->after('max_aircraft');
            }

            if (! Schema::hasColumn('plans', 'has_priority')) {
                $table->boolean('has_priority')->default(false)->after('max_users');
            }

            if (! Schema::hasColumn('plans', 'has_concierge')) {
                $table->boolean('has_concierge')->default(false)->after('has_priority');
            }

            if (! Schema::hasColumn('plans', 'has_reports')) {
                $table->boolean('has_reports')->default(false)->after('has_concierge');
            }

            if (! Schema::hasColumn('plans', 'is_enterprise')) {
                $table->boolean('is_enterprise')->default(false)->after('has_reports');
            }

            if (! Schema::hasColumn('plans', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_enterprise');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            foreach ([
                'is_active',
                'is_enterprise',
                'has_reports',
                'has_concierge',
                'has_priority',
                'max_users',
                'max_aircraft',
                'max_requests',
                'role_target',
                'price_yearly',
                'price_monthly',
                'description',
                'code',
            ] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
