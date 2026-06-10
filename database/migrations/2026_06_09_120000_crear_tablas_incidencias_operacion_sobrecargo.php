<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_operation_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_operation_id')->constrained('operations')->cascadeOnDelete();
            $table->foreignId('crew_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 40);
            $table->string('priority', 20);
            $table->string('status', 20)->default('open');
            $table->text('description');
            $table->text('admin_response')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['crew_operation_id', 'status']);
            $table->index(['crew_id', 'status']);
        });

        Schema::create('crew_operation_incident_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('crew_operation_incidents')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_type')->nullable();
            $table->string('original_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_operation_incident_files');
        Schema::dropIfExists('crew_operation_incidents');
    }
};
