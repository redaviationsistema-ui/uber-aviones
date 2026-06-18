<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('airport_expense_rules')) {
            return;
        }

        Schema::create('airport_expense_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->nullable()->constrained('aircraft')->nullOnDelete();
            $table->string('category', 100)->nullable()->index();
            $table->string('origin_airport_code', 20)->nullable()->index();
            $table->string('destination_airport_code', 20)->nullable()->index();
            $table->string('route_signature', 255)->nullable()->index();
            $table->decimal('expense_fee', 12, 2);
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airport_expense_rules');
    }
};
