<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy migration kept to preserve existing migration history.
        // Marketplace tables now live in one migration file per table.
    }

    public function down(): void
    {
        //
    }
};
