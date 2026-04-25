<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aircraft_documents')) {
            return;
        }

        Schema::create('aircraft_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->string('type', 100)->nullable();
            $table->string('file_url')->nullable();
            $table->string('document_type', 100)->nullable();
            $table->string('document_name', 150)->nullable();
            $table->text('document_url')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_documents');
    }
};
