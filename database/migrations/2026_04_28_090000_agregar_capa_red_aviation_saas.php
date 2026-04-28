<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // La migracion original se separo en varias migraciones por dominio.
        // Este archivo se conserva para no romper historiales ya compartidos.
    }

    public function down(): void
    {
        //
    }
};
