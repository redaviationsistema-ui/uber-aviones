<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_matches', function (Blueprint $table) {
            if (! Schema::hasColumn('request_matches', 'response_deadline')) {
                $table->timestamp('response_deadline')->nullable()->after('status');
            }

            if (! Schema::hasColumn('request_matches', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('response_deadline');
            }

            if (! Schema::hasColumn('request_matches', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('accepted_at');
            }

            if (! Schema::hasColumn('request_matches', 'visibility_payload')) {
                $table->json('visibility_payload')->nullable()->after('rejected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('request_matches', function (Blueprint $table) {
            foreach ([
                'visibility_payload',
                'rejected_at',
                'accepted_at',
                'response_deadline',
            ] as $column) {
                if (Schema::hasColumn('request_matches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
