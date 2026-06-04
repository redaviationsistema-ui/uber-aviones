<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->agregarReferenciasAeropuerto();
        $this->rellenarReferenciasAeropuerto();
        $this->endurecerCoincidenciasSolicitud();
    }

    public function down(): void
    {
        if (Schema::hasTable('request_matches')) {
            Schema::table('request_matches', function (Blueprint $table) {
                try {
                    $table->dropUnique('request_matches_flight_aircraft_provider_unique');
                } catch (\Throwable) {
                }
            });
        }

        foreach ([
            'reservation_legs' => ['origin_airport_id', 'destination_airport_id'],
            'flight_request_legs' => ['origin_airport_id', 'destination_airport_id'],
            'flight_requests' => ['origin_airport_id', 'destination_airport_id'],
            'profiles' => ['base_airport_id'],
            'aircraft' => ['base_airport_id'],
        ] as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($columns, $tableName) {
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($tableName, $column)) {
                        continue;
                    }

                    $table->dropConstrainedForeignId($column);
                }
            });
        }
    }

    private function agregarReferenciasAeropuerto(): void
    {
        if (! Schema::hasTable('airports')) {
            return;
        }

        if (Schema::hasTable('aircraft')) {
            Schema::table('aircraft', function (Blueprint $table) {
                if (! Schema::hasColumn('aircraft', 'base_airport_id')) {
                    $table->foreignId('base_airport_id')
                        ->nullable()
                        ->after('base_airport')
                        ->constrained('airports')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('profiles')) {
            Schema::table('profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('profiles', 'base_airport_id')) {
                    $table->foreignId('base_airport_id')
                        ->nullable()
                        ->after('base_airport')
                        ->constrained('airports')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('flight_requests')) {
            Schema::table('flight_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('flight_requests', 'origin_airport_id')) {
                    $table->foreignId('origin_airport_id')
                        ->nullable()
                        ->after('origin')
                        ->constrained('airports')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('flight_requests', 'destination_airport_id')) {
                    $table->foreignId('destination_airport_id')
                        ->nullable()
                        ->after('destination')
                        ->constrained('airports')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('flight_request_legs')) {
            Schema::table('flight_request_legs', function (Blueprint $table) {
                if (! Schema::hasColumn('flight_request_legs', 'origin_airport_id')) {
                    $table->foreignId('origin_airport_id')
                        ->nullable()
                        ->after('origin')
                        ->constrained('airports')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('flight_request_legs', 'destination_airport_id')) {
                    $table->foreignId('destination_airport_id')
                        ->nullable()
                        ->after('destination')
                        ->constrained('airports')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('reservation_legs')) {
            Schema::table('reservation_legs', function (Blueprint $table) {
                if (! Schema::hasColumn('reservation_legs', 'origin_airport_id')) {
                    $table->foreignId('origin_airport_id')
                        ->nullable()
                        ->after('origin')
                        ->constrained('airports')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('reservation_legs', 'destination_airport_id')) {
                    $table->foreignId('destination_airport_id')
                        ->nullable()
                        ->after('destination')
                        ->constrained('airports')
                        ->nullOnDelete();
                }
            });
        }
    }

    private function rellenarReferenciasAeropuerto(): void
    {
        if (! Schema::hasTable('airports')) {
            return;
        }

        $airportIdsByCode = [];

        foreach (DB::table('airports')->select(['id', 'icao', 'iata'])->get() as $airport) {
            foreach ([$airport->icao, $airport->iata] as $code) {
                $normalized = $this->normalizarCodigoAeropuerto($code);

                if ($normalized !== null && ! isset($airportIdsByCode[$normalized])) {
                    $airportIdsByCode[$normalized] = $airport->id;
                }
            }
        }

        if ($airportIdsByCode === []) {
            return;
        }

        $this->rellenarRelacionSimple('aircraft', 'base_airport', 'base_airport_id', $airportIdsByCode);
        $this->rellenarRelacionSimple('profiles', 'base_airport', 'base_airport_id', $airportIdsByCode);
        $this->rellenarRelacionDoble('flight_requests', 'origin', 'origin_airport_id', 'destination', 'destination_airport_id', $airportIdsByCode);
        $this->rellenarRelacionDoble('flight_request_legs', 'origin', 'origin_airport_id', 'destination', 'destination_airport_id', $airportIdsByCode);
        $this->rellenarRelacionDoble('reservation_legs', 'origin', 'origin_airport_id', 'destination', 'destination_airport_id', $airportIdsByCode);
    }

    private function endurecerCoincidenciasSolicitud(): void
    {
        if (! Schema::hasTable('request_matches')) {
            return;
        }

        $duplicates = DB::table('request_matches')
            ->selectRaw('MIN(id) as keep_id, flight_request_id, aircraft_id, provider_id, COUNT(*) as total')
            ->groupBy(['flight_request_id', 'aircraft_id', 'provider_id'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('request_matches')
                ->where('flight_request_id', $duplicate->flight_request_id)
                ->where('aircraft_id', $duplicate->aircraft_id)
                ->where('provider_id', $duplicate->provider_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('request_matches', function (Blueprint $table) {
            $table->unique(
                ['flight_request_id', 'aircraft_id', 'provider_id'],
                'request_matches_flight_aircraft_provider_unique'
            );
        });
    }

    private function rellenarRelacionSimple(
        string $tableName,
        string $legacyColumn,
        string $foreignKeyColumn,
        array $airportIdsByCode
    ): void {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $legacyColumn) || ! Schema::hasColumn($tableName, $foreignKeyColumn)) {
            return;
        }

        DB::table($tableName)
            ->select(['id', $legacyColumn, $foreignKeyColumn])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($tableName, $legacyColumn, $foreignKeyColumn, $airportIdsByCode) {
                foreach ($rows as $row) {
                    if ($row->{$foreignKeyColumn} !== null) {
                        continue;
                    }

                    $airportId = $this->resolverAirportId($row->{$legacyColumn}, $airportIdsByCode);

                    if ($airportId === null) {
                        continue;
                    }

                    DB::table($tableName)
                        ->where('id', $row->id)
                        ->update([$foreignKeyColumn => $airportId]);
                }
            });
    }

    private function rellenarRelacionDoble(
        string $tableName,
        string $originColumn,
        string $originForeignKeyColumn,
        string $destinationColumn,
        string $destinationForeignKeyColumn,
        array $airportIdsByCode
    ): void {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        DB::table($tableName)
            ->select(['id', $originColumn, $originForeignKeyColumn, $destinationColumn, $destinationForeignKeyColumn])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (
                $tableName,
                $originColumn,
                $originForeignKeyColumn,
                $destinationColumn,
                $destinationForeignKeyColumn,
                $airportIdsByCode
            ) {
                foreach ($rows as $row) {
                    $updates = [];

                    if ($row->{$originForeignKeyColumn} === null) {
                        $originAirportId = $this->resolverAirportId($row->{$originColumn}, $airportIdsByCode);

                        if ($originAirportId !== null) {
                            $updates[$originForeignKeyColumn] = $originAirportId;
                        }
                    }

                    if ($row->{$destinationForeignKeyColumn} === null) {
                        $destinationAirportId = $this->resolverAirportId($row->{$destinationColumn}, $airportIdsByCode);

                        if ($destinationAirportId !== null) {
                            $updates[$destinationForeignKeyColumn] = $destinationAirportId;
                        }
                    }

                    if ($updates === []) {
                        continue;
                    }

                    DB::table($tableName)
                        ->where('id', $row->id)
                        ->update($updates);
                }
            });
    }

    private function resolverAirportId(mixed $value, array $airportIdsByCode): ?int
    {
        $normalized = $this->normalizarCodigoAeropuerto($value);

        if ($normalized === null) {
            return null;
        }

        return $airportIdsByCode[$normalized] ?? null;
    }

    private function normalizarCodigoAeropuerto(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtoupper(trim($value));

        return $normalized !== '' ? $normalized : null;
    }
};
