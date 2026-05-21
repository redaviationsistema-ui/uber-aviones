<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webhook_events')) {
            return;
        }

        Schema::table('webhook_events', function (Blueprint $table) {
            if (! Schema::hasColumn('webhook_events', 'stripe_created_at_utc')) {
                $table->timestamp('stripe_created_at_utc')->nullable()->after('payload')->index();
            }

            if (! Schema::hasColumn('webhook_events', 'stripe_created_at_local')) {
                $table->timestamp('stripe_created_at_local')->nullable()->after('stripe_created_at_utc')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('webhook_events')) {
            return;
        }

        Schema::table('webhook_events', function (Blueprint $table) {
            $dropColumns = [];

            foreach (['stripe_created_at_utc', 'stripe_created_at_local'] as $column) {
                if (Schema::hasColumn('webhook_events', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
