<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists request_matches_provider_status_request_idx on request_matches (provider_id, status, flight_request_id)');
        DB::statement('create index if not exists request_matches_provider_request_idx on request_matches (provider_id, flight_request_id)');
        DB::statement('create index if not exists flight_requests_assigned_provider_created_idx on flight_requests (assigned_provider_id, created_at desc)');
        DB::statement('create index if not exists aircraft_provider_created_idx on aircraft (provider_id, created_at desc)');
        DB::statement('create index if not exists operations_request_id_id_idx on operations (flight_request_id, id desc)');
        DB::statement('create index if not exists payments_reservation_id_id_idx on payments (reservation_id, id desc)');
        DB::statement('create index if not exists quotes_provider_status_idx on quotes (provider_id, status)');
        DB::statement('create index if not exists payouts_provider_status_idx on payouts (provider_id, status)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists request_matches_provider_status_request_idx');
        DB::statement('drop index if exists request_matches_provider_request_idx');
        DB::statement('drop index if exists flight_requests_assigned_provider_created_idx');
        DB::statement('drop index if exists aircraft_provider_created_idx');
        DB::statement('drop index if exists operations_request_id_id_idx');
        DB::statement('drop index if exists payments_reservation_id_id_idx');
        DB::statement('drop index if exists quotes_provider_status_idx');
        DB::statement('drop index if exists payouts_provider_status_idx');
    }
};
