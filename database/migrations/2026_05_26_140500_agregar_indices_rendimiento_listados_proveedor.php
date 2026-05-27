<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists aircraft_images_aircraft_id_sort_idx on aircraft_images (aircraft_id, is_main desc, sort_order asc, id asc)');
        DB::statement('create index if not exists aircraft_documents_aircraft_id_idx on aircraft_documents (aircraft_id)');
        DB::statement('create index if not exists aircraft_availability_aircraft_id_idx on aircraft_availability (aircraft_id)');
        DB::statement('create index if not exists reservations_flight_request_id_idx on reservations (flight_request_id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists aircraft_images_aircraft_id_sort_idx');
        DB::statement('drop index if exists aircraft_documents_aircraft_id_idx');
        DB::statement('drop index if exists aircraft_availability_aircraft_id_idx');
        DB::statement('drop index if exists reservations_flight_request_id_idx');
    }
};
