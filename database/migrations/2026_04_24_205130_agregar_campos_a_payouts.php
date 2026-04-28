<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('payouts', 'payment_method')) {
                $table->string('payment_method', 100)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('payouts', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('released_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            foreach (['paid_at', 'payment_method'] as $column) {
                if (Schema::hasColumn('payouts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
