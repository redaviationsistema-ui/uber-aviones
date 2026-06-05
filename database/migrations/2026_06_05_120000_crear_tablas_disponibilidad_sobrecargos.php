<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalogo_disponibilidad_estatus')) {
            Schema::create('catalogo_disponibilidad_estatus', function (Blueprint $table) {
                $table->id();
                $table->string('clave', 50)->unique();
                $table->string('nombre', 100);
                $table->string('descripcion', 255)->nullable();
                $table->string('color', 30)->nullable();
                $table->string('icono', 50)->nullable();
                $table->integer('orden')->default(0);
                $table->boolean('seleccionable_sobrecargo')->default(true);
                $table->boolean('seleccionable_admin')->default(true);
                $table->boolean('permite_asignacion')->default(false);
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sobrecargo_disponibilidades')) {
            Schema::create('sobrecargo_disponibilidades', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sobrecargo_id')->constrained('users')->cascadeOnDelete();
                $table->date('fecha');
                $table->foreignId('estatus_id')->constrained('catalogo_disponibilidad_estatus')->restrictOnDelete();
                $table->string('motivo', 255)->nullable();
                $table->text('comentario')->nullable();
                $table->string('origen', 30)->default('SOBRECARGO');
                $table->foreignId('operacion_id')->nullable()->constrained('operations')->nullOnDelete();
                $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('aprobado_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('bitacora')->nullable();
                $table->timestamps();

                $table->unique(['sobrecargo_id', 'fecha'], 'uq_sobrecargo_fecha');
                $table->index('fecha');
                $table->index('estatus_id');
                $table->index('operacion_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sobrecargo_disponibilidades');
        Schema::dropIfExists('catalogo_disponibilidad_estatus');
    }
};
