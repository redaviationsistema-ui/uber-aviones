<?php

use Database\Seeders\CatalogoDisponibilidadEstatusSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new CatalogoDisponibilidadEstatusSeeder)->seedMissing();
    }

    public function down(): void
    {
        // Preserve reference data: availability records may already use these IDs.
    }
};
