<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('category_pricing_rules')) {
            return;
        }

        Schema::create('category_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('category', 100)->unique();
            $table->decimal('minimum_route_price', 12, 2)->default(0);
            $table->decimal('redsky_markup', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_pricing_rules');
    }
};
