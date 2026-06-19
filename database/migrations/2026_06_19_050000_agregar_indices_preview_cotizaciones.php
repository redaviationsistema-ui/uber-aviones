<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('airports')) {
            Schema::table('airports', function (Blueprint $table) {
                if (! $this->indexExists('airports', 'airports_status_icao_index')) {
                    $table->index(['status', 'icao'], 'airports_status_icao_index');
                }

                if (! $this->indexExists('airports', 'airports_status_iata_index')) {
                    $table->index(['status', 'iata'], 'airports_status_iata_index');
                }

                if (Schema::hasColumn('airports', 'icao_code') && ! $this->indexExists('airports', 'airports_status_icao_code_index')) {
                    $table->index(['status', 'icao_code'], 'airports_status_icao_code_index');
                }

                if (Schema::hasColumn('airports', 'iata_code') && ! $this->indexExists('airports', 'airports_status_iata_code_index')) {
                    $table->index(['status', 'iata_code'], 'airports_status_iata_code_index');
                }
            });
        }

        if (Schema::hasTable('airport_expense_rules')) {
            Schema::table('airport_expense_rules', function (Blueprint $table) {
                if (! $this->indexExists('airport_expense_rules', 'airport_expense_rules_active_aircraft_index')) {
                    $table->index(['is_active', 'aircraft_id'], 'airport_expense_rules_active_aircraft_index');
                }

                if (! $this->indexExists('airport_expense_rules', 'airport_expense_rules_active_route_index')) {
                    $table->index(['is_active', 'route_signature'], 'airport_expense_rules_active_route_index');
                }

                if (! $this->indexExists('airport_expense_rules', 'airport_expense_rules_active_route_codes_index')) {
                    $table->index(
                        ['is_active', 'origin_airport_code', 'destination_airport_code', 'category'],
                        'airport_expense_rules_active_route_codes_index'
                    );
                }
            });
        }

        if (Schema::hasTable('aircraft')) {
            Schema::table('aircraft', function (Blueprint $table) {
                if (! $this->indexExists('aircraft', 'aircraft_quote_preview_index')) {
                    $table->index(['status', 'capacity', 'hourly_rate'], 'aircraft_quote_preview_index');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('aircraft')) {
            Schema::table('aircraft', function (Blueprint $table) {
                if ($this->indexExists('aircraft', 'aircraft_quote_preview_index')) {
                    $table->dropIndex('aircraft_quote_preview_index');
                }
            });
        }

        if (Schema::hasTable('airport_expense_rules')) {
            Schema::table('airport_expense_rules', function (Blueprint $table) {
                foreach ([
                    'airport_expense_rules_active_aircraft_index',
                    'airport_expense_rules_active_route_index',
                    'airport_expense_rules_active_route_codes_index',
                ] as $indexName) {
                    if ($this->indexExists('airport_expense_rules', $indexName)) {
                        $table->dropIndex($indexName);
                    }
                }
            });
        }

        if (Schema::hasTable('airports')) {
            Schema::table('airports', function (Blueprint $table) {
                foreach ([
                    'airports_status_icao_index',
                    'airports_status_iata_index',
                    'airports_status_icao_code_index',
                    'airports_status_iata_code_index',
                ] as $indexName) {
                    if ($this->indexExists('airports', $indexName)) {
                        $table->dropIndex($indexName);
                    }
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                $name = $index['name'] ?? $index['index_name'] ?? null;

                if (is_string($name) && strtolower($name) === strtolower($indexName)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
