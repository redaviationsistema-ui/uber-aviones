<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reservations')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::transaction(function (): void {
                DB::statement('PRAGMA foreign_keys=OFF');

                DB::statement('
                    CREATE TABLE reservations_tmp (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        client_id INTEGER NOT NULL,
                        provider_id INTEGER NOT NULL,
                        aircraft_id INTEGER NOT NULL,
                        flight_request_id INTEGER NOT NULL,
                        quote_id INTEGER NULL,
                        reservation_code VARCHAR(50) NOT NULL,
                        status VARCHAR(255) NOT NULL DEFAULT "pending_payment",
                        total_amount NUMERIC NOT NULL DEFAULT 0,
                        currency VARCHAR(10) NOT NULL DEFAULT "USD",
                        confirmed_at DATETIME NULL,
                        cancelled_at DATETIME NULL,
                        created_at DATETIME NULL,
                        updated_at DATETIME NULL,
                        FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
                        FOREIGN KEY (aircraft_id) REFERENCES aircraft(id) ON DELETE CASCADE,
                        FOREIGN KEY (flight_request_id) REFERENCES flight_requests(id) ON DELETE CASCADE,
                        FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
                    )
                ');

                DB::statement('
                    INSERT INTO reservations_tmp (
                        id, client_id, provider_id, aircraft_id, flight_request_id, quote_id,
                        reservation_code, status, total_amount, currency,
                        confirmed_at, cancelled_at, created_at, updated_at
                    )
                    SELECT
                        id, client_id, provider_id, aircraft_id, flight_request_id, quote_id,
                        reservation_code, status, total_amount, currency,
                        confirmed_at, cancelled_at, created_at, updated_at
                    FROM reservations
                ');

                DB::statement('DROP TABLE reservations');
                DB::statement('ALTER TABLE reservations_tmp RENAME TO reservations');
                DB::statement('CREATE UNIQUE INDEX reservations_quote_id_unique ON reservations (quote_id)');
                DB::statement('CREATE UNIQUE INDEX reservations_reservation_code_unique ON reservations (reservation_code)');
                DB::statement('CREATE INDEX reservations_status_index ON reservations (status)');
                DB::statement('CREATE INDEX reservations_confirmed_at_index ON reservations (confirmed_at)');
                DB::statement('CREATE INDEX reservations_cancelled_at_index ON reservations (cancelled_at)');
                DB::statement('PRAGMA foreign_keys=ON');
            });

            return;
        }

        DB::statement('ALTER TABLE reservations ALTER COLUMN quote_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservations')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::transaction(function (): void {
                DB::statement('PRAGMA foreign_keys=OFF');

                DB::statement('
                    CREATE TABLE reservations_tmp (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        client_id INTEGER NOT NULL,
                        provider_id INTEGER NOT NULL,
                        aircraft_id INTEGER NOT NULL,
                        flight_request_id INTEGER NOT NULL,
                        quote_id INTEGER NOT NULL,
                        reservation_code VARCHAR(50) NOT NULL,
                        status VARCHAR(255) NOT NULL DEFAULT "pending_payment",
                        total_amount NUMERIC NOT NULL DEFAULT 0,
                        currency VARCHAR(10) NOT NULL DEFAULT "USD",
                        confirmed_at DATETIME NULL,
                        cancelled_at DATETIME NULL,
                        created_at DATETIME NULL,
                        updated_at DATETIME NULL,
                        FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
                        FOREIGN KEY (aircraft_id) REFERENCES aircraft(id) ON DELETE CASCADE,
                        FOREIGN KEY (flight_request_id) REFERENCES flight_requests(id) ON DELETE CASCADE,
                        FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
                    )
                ');

                DB::statement('
                    INSERT INTO reservations_tmp (
                        id, client_id, provider_id, aircraft_id, flight_request_id, quote_id,
                        reservation_code, status, total_amount, currency,
                        confirmed_at, cancelled_at, created_at, updated_at
                    )
                    SELECT
                        id, client_id, provider_id, aircraft_id, flight_request_id, quote_id,
                        reservation_code, status, total_amount, currency,
                        confirmed_at, cancelled_at, created_at, updated_at
                    FROM reservations
                    WHERE quote_id IS NOT NULL
                ');

                DB::statement('DROP TABLE reservations');
                DB::statement('ALTER TABLE reservations_tmp RENAME TO reservations');
                DB::statement('CREATE UNIQUE INDEX reservations_quote_id_unique ON reservations (quote_id)');
                DB::statement('CREATE UNIQUE INDEX reservations_reservation_code_unique ON reservations (reservation_code)');
                DB::statement('CREATE INDEX reservations_status_index ON reservations (status)');
                DB::statement('CREATE INDEX reservations_confirmed_at_index ON reservations (confirmed_at)');
                DB::statement('CREATE INDEX reservations_cancelled_at_index ON reservations (cancelled_at)');
                DB::statement('PRAGMA foreign_keys=ON');
            });

            return;
        }

        DB::statement('ALTER TABLE reservations ALTER COLUMN quote_id SET NOT NULL');
    }
};
