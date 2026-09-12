<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
    private function links(): array {
        return [
            ['reservations', 'client_id', 'users'], ['reservations', 'provider_id', 'providers'],
            ['reservations', 'aircraft_id', 'aircraft'], ['reservations', 'flight_request_id', 'flight_requests'],
            ['reservations', 'quote_id', 'quotes'], ['reservation_contracts', 'reservation_id', 'reservations'],
            ['operations', 'flight_request_id', 'flight_requests'], ['quotes', 'flight_request_id', 'flight_requests'],
            ['flight_requests', 'client_id', 'users'], ['providers', 'user_id', 'users'], ['aircraft', 'provider_id', 'providers'],
        ];
    }
    public function up(): void {
        if (DB::table('operations')->select('flight_request_id')->whereNotNull('flight_request_id')->groupBy('flight_request_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate operation links: review history manually; migration does not clean data.');
        }
        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot rebuild these legacy double-quoted defaults safely.
            // BEFORE DELETE guards provide the same restriction without copying rows.
            foreach ($this->links() as [$child, $column, $parent]) {
                DB::unprepared("CREATE TRIGGER history_restrict_{$child}_{$column} BEFORE DELETE ON {$parent} WHEN EXISTS (SELECT 1 FROM {$child} WHERE {$column} = OLD.id) BEGIN SELECT RAISE(ABORT, 'Commercial history is protected'); END");
            }
            Schema::table('operations', fn (Blueprint $table) => $table->unique('flight_request_id', 'operations_flight_request_unique'));
            return;
        }
        foreach ($this->links() as [$child, $column, $parent]) {
            Schema::table($child, function (Blueprint $table) use ($column, $parent) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on($parent)->restrictOnDelete();
            });
        }
        Schema::table('operations', fn (Blueprint $table) => $table->unique('flight_request_id', 'operations_flight_request_unique'));
    }
    public function down(): void {
        // Restoring cascades would silently re-enable historical data loss.
        throw new RuntimeException('Historical protection requires an explicitly reviewed rollback.');
    }
};
