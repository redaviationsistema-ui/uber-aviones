<?php

use App\Modelos\Aeronave;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('aircraft')
            ->whereIn('category', ['Helicoptero', 'Helicóptero'])
            ->where('climb_descent_minutes', 25)
            ->where(function ($query): void {
                $query
                    ->whereNull('climb_descent_source')
                    ->orWhereIn('climb_descent_source', [
                        Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT,
                        Aeronave::CLIMB_DESCENT_SOURCE_GLOBAL_DEFAULT,
                        Aeronave::CLIMB_DESCENT_SOURCE_LEGACY_UNKNOWN,
                    ]);
            })
            ->update([
                'climb_descent_minutes' => (int) config('vuelos.climb_descent_defaults.Helicoptero', 15),
                'climb_descent_source' => Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT,
            ]);
    }

    public function down(): void
    {
        DB::table('aircraft')
            ->whereIn('category', ['Helicoptero', 'Helicóptero'])
            ->where('climb_descent_minutes', (int) config('vuelos.climb_descent_defaults.Helicoptero', 15))
            ->where('climb_descent_source', Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT)
            ->update([
                'climb_descent_minutes' => 25,
            ]);
    }
};
