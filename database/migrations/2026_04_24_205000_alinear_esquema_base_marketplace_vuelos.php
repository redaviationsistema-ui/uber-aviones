<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('profiles', 'avatar_url')) {
                $table->text('avatar_url')->nullable()->after('avatar');
            }
        });

        Schema::table('airports', function (Blueprint $table) {
            if (! Schema::hasColumn('airports', 'icao_code')) {
                $table->string('icao_code', 10)->nullable()->after('id');
            }

            if (! Schema::hasColumn('airports', 'iata_code')) {
                $table->string('iata_code', 10)->nullable()->after('icao_code');
            }
        });

        DB::table('airports')->whereNull('icao_code')->whereNotNull('icao')->update(['icao_code' => DB::raw('icao')]);
        DB::table('airports')->whereNull('iata_code')->whereNotNull('iata')->update(['iata_code' => DB::raw('iata')]);

        Schema::table('aircraft', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft', 'currency')) {
                $table->string('currency', 10)->default('USD')->after('hourly_rate');
            }
        });

        Schema::table('aircraft_images', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft_images', 'is_main')) {
                $table->boolean('is_main')->default(false)->after('sort_order');
            }
        });

        Schema::table('aircraft_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft_documents', 'document_type')) {
                $table->string('document_type', 100)->nullable()->after('aircraft_id');
            }

            if (! Schema::hasColumn('aircraft_documents', 'document_name')) {
                $table->string('document_name', 150)->nullable()->after('document_type');
            }

            if (! Schema::hasColumn('aircraft_documents', 'document_url')) {
                $table->text('document_url')->nullable()->after('document_name');
            }
        });

        DB::table('aircraft_documents')->whereNull('document_type')->whereNotNull('type')->update(['document_type' => DB::raw('type')]);
        DB::table('aircraft_documents')->whereNull('document_url')->whereNotNull('file_url')->update(['document_url' => DB::raw('file_url')]);

        Schema::table('aircraft_availability', function (Blueprint $table) {
            if (! Schema::hasColumn('aircraft_availability', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
        });

        Schema::table('flight_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_requests', 'departure_datetime')) {
                $table->timestamp('departure_datetime')->nullable()->after('destination');
            }

            if (! Schema::hasColumn('flight_requests', 'return_datetime')) {
                $table->timestamp('return_datetime')->nullable()->after('departure_datetime');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("update flight_requests set departure_datetime = (departure_date::text || ' ' || departure_time::text)::timestamp where departure_datetime is null and departure_date is not null and departure_time is not null");
            DB::statement('alter table flight_requests drop constraint if exists flight_requests_trip_type_check');
            DB::statement("alter table flight_requests add constraint flight_requests_trip_type_check check (trip_type in ('one_way', 'round_trip', 'multi_leg'))");
        }

        Schema::table('flight_request_legs', function (Blueprint $table) {
            if (! Schema::hasColumn('flight_request_legs', 'passengers')) {
                $table->unsignedInteger('passengers')->default(1)->after('departure_datetime');
            }
        });

        Schema::table('request_matches', function (Blueprint $table) {
            if (! Schema::hasColumn('request_matches', 'estimated_price')) {
                $table->decimal('estimated_price', 12, 2)->nullable()->after('match_score');
            }
        });

        Schema::table('quote_items', function (Blueprint $table) {
            if (! Schema::hasColumn('quote_items', 'concept')) {
                $table->string('concept', 150)->nullable()->after('quote_id');
            }
        });

        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'currency')) {
                $table->string('currency', 10)->default('USD')->after('total_amount');
            }
        });

        Schema::table('reservation_legs', function (Blueprint $table) {
            if (! Schema::hasColumn('reservation_legs', 'passengers')) {
                $table->unsignedInteger('passengers')->default(1)->after('departure_datetime');
            }
        });

        Schema::table('payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('payouts', 'payment_method')) {
                $table->string('payment_method', 100)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('payouts', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('released_at');
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'data')) {
                $table->json('data')->nullable()->after('message');
            }
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('ip_address');
            }

            if (! Schema::hasColumn('audit_logs', 'old_values')) {
                $table->json('old_values')->nullable()->after('user_agent');
            }

            if (! Schema::hasColumn('audit_logs', 'new_values')) {
                $table->json('new_values')->nullable()->after('old_values');
            }
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_logs' => ['new_values', 'old_values', 'user_agent'],
            'notifications' => ['data'],
            'payouts' => ['paid_at', 'payment_method'],
            'reservation_legs' => ['passengers'],
            'reservations' => ['currency'],
            'quote_items' => ['concept'],
            'request_matches' => ['estimated_price'],
            'flight_request_legs' => ['passengers'],
            'flight_requests' => ['return_datetime', 'departure_datetime'],
            'aircraft_availability' => ['notes'],
            'aircraft_documents' => ['document_url', 'document_name', 'document_type'],
            'aircraft_images' => ['is_main'],
            'aircraft' => ['currency'],
            'airports' => ['iata_code', 'icao_code'],
            'profiles' => ['avatar_url'],
        ] as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
