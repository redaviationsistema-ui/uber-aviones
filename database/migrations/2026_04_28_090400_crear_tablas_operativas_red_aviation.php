<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operations')) {
            Schema::create('operations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('flight_request_id')->constrained('flight_requests')->cascadeOnDelete();
                $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
                $table->foreignId('aircraft_id')->nullable()->constrained('aircraft')->nullOnDelete();
                $table->foreignId('sobrecargo_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 50)->default('pendiente')->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('operation_timeline')) {
            Schema::create('operation_timeline', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
                $table->string('status', 50)->index();
                $table->string('title', 150);
                $table->text('description')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sobrecargo_assignments')) {
            Schema::create('sobrecargo_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
                $table->foreignId('sobrecargo_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 50)->default('asignado')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('checklists')) {
            Schema::create('checklists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
                $table->foreignId('sobrecargo_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 100)->default('servicio');
                $table->string('status', 50)->default('pendiente')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('checklist_items')) {
            Schema::create('checklist_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('checklist_id')->constrained('checklists')->cascadeOnDelete();
                $table->string('label');
                $table->boolean('is_completed')->default(false)->index();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklists');
        Schema::dropIfExists('sobrecargo_assignments');
        Schema::dropIfExists('operation_timeline');
        Schema::dropIfExists('operations');
    }
};
