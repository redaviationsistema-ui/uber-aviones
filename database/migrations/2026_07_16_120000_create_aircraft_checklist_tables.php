<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aircraft_checklists')) {
            Schema::create('aircraft_checklists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique('aircraft_id');
            });
        }

        if (! Schema::hasTable('aircraft_checklist_items')) {
            Schema::create('aircraft_checklist_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('checklist_id')->constrained('aircraft_checklists')->cascadeOnDelete();
                $table->string('item_key', 120);
                $table->string('label', 255);
                $table->string('status', 30)->default('pending')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['checklist_id', 'item_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_checklist_items');
        Schema::dropIfExists('aircraft_checklists');
    }
};
