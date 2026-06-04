<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            DB::statement('create index if not exists users_created_id_desc_idx on users (created_at desc, id desc)');
            DB::statement('create index if not exists users_provider_id_idx on users (provider_id)');
            DB::statement('create index if not exists users_operational_role_idx on users (operational_role)');
        }

        if (Schema::hasTable('user_roles')) {
            DB::statement('create index if not exists user_roles_role_user_idx on user_roles (role_id, user_id)');
        }

        if (Schema::hasTable('subscriptions')) {
            DB::statement('create index if not exists subscriptions_user_status_expiry_idx on subscriptions (user_id, status, expires_at desc, id desc)');
        }

        if (Schema::hasTable('identity_verifications')) {
            DB::statement('create index if not exists identity_verifications_user_created_idx on identity_verifications (user_id, created_at desc, id desc)');
        }

        if (Schema::hasTable('request_matches')) {
            DB::statement('create index if not exists request_matches_request_status_aircraft_idx on request_matches (flight_request_id, status, aircraft_id)');
            DB::statement('create index if not exists request_matches_aircraft_idx on request_matches (aircraft_id)');
            DB::statement('create index if not exists request_matches_provider_idx on request_matches (provider_id)');
        }

        if (Schema::hasTable('operations')) {
            DB::statement('create index if not exists operations_request_latest_idx on operations (flight_request_id, id desc)');
        }

        if (Schema::hasTable('reservations')) {
            DB::statement('create index if not exists reservations_request_latest_idx on reservations (flight_request_id, id desc)');
        }

        if (Schema::hasTable('payments')) {
            DB::statement('create index if not exists payments_reservation_latest_idx on payments (reservation_id, id desc)');
        }
    }

    public function down(): void
    {
        DB::statement('drop index if exists users_created_id_desc_idx');
        DB::statement('drop index if exists users_provider_id_idx');
        DB::statement('drop index if exists users_operational_role_idx');
        DB::statement('drop index if exists user_roles_role_user_idx');
        DB::statement('drop index if exists subscriptions_user_status_expiry_idx');
        DB::statement('drop index if exists identity_verifications_user_created_idx');
        DB::statement('drop index if exists request_matches_request_status_aircraft_idx');
        DB::statement('drop index if exists request_matches_aircraft_idx');
        DB::statement('drop index if exists request_matches_provider_idx');
        DB::statement('drop index if exists operations_request_latest_idx');
        DB::statement('drop index if exists reservations_request_latest_idx');
        DB::statement('drop index if exists payments_reservation_latest_idx');
    }
};
