<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aircraft')) {
            DB::statement('create index if not exists aircraft_status_base_airport_capacity_idx on aircraft (status, base_airport_id, capacity)');
            DB::statement('create index if not exists aircraft_provider_status_created_idx on aircraft (provider_id, status, created_at desc)');
        }

        if (Schema::hasTable('aircraft_availability')) {
            DB::statement('create index if not exists aircraft_availability_aircraft_status_window_idx on aircraft_availability (aircraft_id, status, start_datetime, end_datetime)');
        }

        if (Schema::hasTable('flight_requests')) {
            DB::statement('create index if not exists flight_requests_client_created_idx on flight_requests (client_id, created_at desc)');
            DB::statement('create index if not exists flight_requests_status_workflow_created_idx on flight_requests (status, workflow_status, created_at desc)');
            DB::statement('create index if not exists flight_requests_origin_airport_created_idx on flight_requests (origin_airport_id, created_at desc)');
        }

        if (Schema::hasTable('payments')) {
            DB::statement('create index if not exists payments_user_status_created_idx on payments (user_id, status, created_at desc)');
            DB::statement('create index if not exists payments_flight_request_status_idx on payments (flight_request_id, status)');
        }

        if (Schema::hasTable('notifications')) {
            DB::statement('create index if not exists notifications_user_read_created_idx on notifications (user_id, read_at, created_at desc)');
        }

        if (Schema::hasTable('protected_chats')) {
            DB::statement('create index if not exists protected_chats_request_provider_status_idx on protected_chats (flight_request_id, provider_id, status)');
        }

        if (Schema::hasTable('chat_messages')) {
            DB::statement('create index if not exists chat_messages_chat_created_idx on chat_messages (chat_id, created_at desc)');
        }

        if (Schema::hasTable('anti_broker_flags')) {
            DB::statement('create index if not exists anti_broker_flags_request_status_severity_idx on anti_broker_flags (flight_request_id, status, severity)');
        }

        if (Schema::hasTable('operations')) {
            DB::statement('create index if not exists operations_provider_status_idx on operations (provider_id, status)');
        }

        if (Schema::hasTable('operation_timeline')) {
            DB::statement('create index if not exists operation_timeline_operation_status_idx on operation_timeline (operation_id, status)');
        }

        if (Schema::hasTable('quotes')) {
            DB::statement('create index if not exists quotes_provider_status_created_idx on quotes (provider_id, status, created_at desc)');
        }

        if (Schema::hasTable('reservations')) {
            DB::statement('create index if not exists reservations_provider_status_created_idx on reservations (provider_id, status, created_at desc)');
        }

        if (Schema::hasTable('payouts')) {
            DB::statement('create index if not exists payouts_provider_status_created_idx on payouts (provider_id, status, created_at desc)');
        }
    }

    public function down(): void
    {
        DB::statement('drop index if exists aircraft_status_base_airport_capacity_idx');
        DB::statement('drop index if exists aircraft_provider_status_created_idx');
        DB::statement('drop index if exists aircraft_availability_aircraft_status_window_idx');
        DB::statement('drop index if exists flight_requests_client_created_idx');
        DB::statement('drop index if exists flight_requests_status_workflow_created_idx');
        DB::statement('drop index if exists flight_requests_origin_airport_created_idx');
        DB::statement('drop index if exists payments_user_status_created_idx');
        DB::statement('drop index if exists payments_flight_request_status_idx');
        DB::statement('drop index if exists notifications_user_read_created_idx');
        DB::statement('drop index if exists protected_chats_request_provider_status_idx');
        DB::statement('drop index if exists chat_messages_chat_created_idx');
        DB::statement('drop index if exists anti_broker_flags_request_status_severity_idx');
        DB::statement('drop index if exists operations_provider_status_idx');
        DB::statement('drop index if exists operation_timeline_operation_status_idx');
        DB::statement('drop index if exists quotes_provider_status_created_idx');
        DB::statement('drop index if exists reservations_provider_status_created_idx');
        DB::statement('drop index if exists payouts_provider_status_created_idx');
    }
};
