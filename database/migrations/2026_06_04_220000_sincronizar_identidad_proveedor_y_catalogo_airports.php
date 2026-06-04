<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STANDARD_ROLES = [
        'client' => ['name' => 'Cliente', 'type' => 'base', 'description' => 'Usuario que cotiza y reserva vuelos.'],
        'provider' => ['name' => 'Proveedor', 'type' => 'base', 'description' => 'Operador o proveedor que publica aeronaves y cotiza.'],
        'admin' => ['name' => 'Administrador', 'type' => 'system', 'description' => 'Usuario con control total del sistema.'],
        'sobrecargo' => ['name' => 'Sobrecargo', 'type' => 'operational', 'description' => 'Usuario operativo asignado a servicios y checklists.'],
    ];

    private const BASE_ROLES = ['client', 'provider', 'admin'];

    public function up(): void
    {
        $this->estandarizarCatalogoAirports();
        $this->sincronizarGobiernoProveedorRoles();
    }

    public function down(): void
    {
        // Esta migracion sanea y alinea datos existentes. No es seguro revertirla automaticamente.
    }

    private function estandarizarCatalogoAirports(): void
    {
        if (! Schema::hasTable('airports')) {
            return;
        }

        Schema::table('airports', function (Blueprint $table) {
            if (! Schema::hasColumn('airports', 'icao_code')) {
                $table->string('icao_code', 10)->nullable()->after('iata');
            }

            if (! Schema::hasColumn('airports', 'iata_code')) {
                $table->string('iata_code', 10)->nullable()->after('icao_code');
            }

            if (! Schema::hasColumn('airports', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('airports', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        DB::table('airports')
            ->whereNull('icao_code')
            ->whereNotNull('icao')
            ->update(['icao_code' => DB::raw('icao')]);

        DB::table('airports')
            ->whereNull('iata_code')
            ->whereNotNull('iata')
            ->update(['iata_code' => DB::raw('iata')]);
    }

    private function sincronizarGobiernoProveedorRoles(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('providers') || ! Schema::hasTable('roles') || ! Schema::hasTable('user_roles')) {
            return;
        }

        $this->asegurarRolesBase();

        $providerRoleId = DB::table('roles')->where('code', 'provider')->value('id');
        $clientRoleId = DB::table('roles')->where('code', 'client')->value('id');

        if (! $providerRoleId || ! $clientRoleId) {
            return;
        }

        $now = now();

        DB::table('providers')
            ->select(['id', 'user_id'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $providers) use ($providerRoleId, $now) {
                foreach ($providers as $provider) {
                    DB::table('users')
                        ->where('id', $provider->user_id)
                        ->update(['provider_id' => $provider->id]);

                    DB::table('user_roles')->upsert([
                        [
                            'user_id' => $provider->user_id,
                            'role_id' => $providerRoleId,
                            'is_primary' => false,
                            'assigned_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    ], ['user_id', 'role_id'], ['assigned_at', 'updated_at']);
                }
            });

        DB::table('users')
            ->whereNotNull('provider_id')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $users) use ($providerRoleId, $now) {
                $rows = [];

                foreach ($users as $user) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'role_id' => $providerRoleId,
                        'is_primary' => false,
                        'assigned_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('user_roles')->upsert(
                        $rows,
                        ['user_id', 'role_id'],
                        ['assigned_at', 'updated_at']
                    );
                }
            });

        DB::table('users')
            ->select(['id', 'role'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $users) use ($clientRoleId, $now) {
                $rows = [];

                foreach ($users as $user) {
                    $hasAnyRole = DB::table('user_roles')
                        ->where('user_id', $user->id)
                        ->exists();

                    if ($hasAnyRole) {
                        continue;
                    }

                    $rows[] = [
                        'user_id' => $user->id,
                        'role_id' => $clientRoleId,
                        'is_primary' => true,
                        'assigned_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('user_roles')->insert($rows);
                }
            });

        $this->normalizarPrimariosYLegacy();
    }

    private function asegurarRolesBase(): void
    {
        $now = now();
        $rows = [];

        foreach (self::STANDARD_ROLES as $code => $meta) {
            $rows[] = [
                'code' => $code,
                'name' => $meta['name'],
                'type' => $meta['type'],
                'description' => $meta['description'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('roles')->upsert(
            $rows,
            ['code'],
            ['name', 'type', 'description', 'is_active', 'updated_at']
        );
    }

    private function normalizarPrimariosYLegacy(): void
    {
        $rolesById = DB::table('roles')->pluck('code', 'id');

        DB::table('users')
            ->select(['id', 'role', 'operational_role', 'provider_id'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $users) use ($rolesById) {
                foreach ($users as $user) {
                    $roleAssignments = DB::table('user_roles')
                        ->where('user_id', $user->id)
                        ->orderByDesc('is_primary')
                        ->orderBy('assigned_at')
                        ->orderBy('id')
                        ->get(['id', 'role_id', 'is_primary']);

                    if ($roleAssignments->isEmpty()) {
                        continue;
                    }

                    $roleCodes = $roleAssignments
                        ->map(fn ($assignment) => $rolesById[$assignment->role_id] ?? null)
                        ->filter(fn ($code) => is_string($code) && $code !== '')
                        ->values();

                    $desiredPrimaryCode = $this->resolverRolPrimarioDeseado(
                        $roleCodes,
                        $user->role,
                        $user->operational_role,
                        $user->provider_id
                    );

                    $desiredPrimaryId = $roleAssignments
                        ->first(fn ($assignment) => ($rolesById[$assignment->role_id] ?? null) === $desiredPrimaryCode)?->id
                        ?? $roleAssignments->first()->id;

                    DB::table('user_roles')
                        ->where('user_id', $user->id)
                        ->update(['is_primary' => false, 'updated_at' => now()]);

                    DB::table('user_roles')
                        ->where('id', $desiredPrimaryId)
                        ->update(['is_primary' => true, 'updated_at' => now()]);

                    [$baseRole, $operationalRole] = $this->resolverColumnasLegacy($roleCodes->all(), $desiredPrimaryCode);

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'role' => $baseRole,
                            'operational_role' => $operationalRole,
                        ]);
                }
            });
    }

    private function resolverRolPrimarioDeseado(
        Collection $roleCodes,
        mixed $legacyRole,
        mixed $legacyOperationalRole,
        mixed $providerId
    ): string {
        if (is_string($legacyOperationalRole) && $roleCodes->contains($legacyOperationalRole)) {
            return $legacyOperationalRole;
        }

        if (is_string($legacyRole) && $roleCodes->contains($legacyRole)) {
            return $legacyRole;
        }

        if ($providerId !== null && $roleCodes->contains('provider')) {
            return 'provider';
        }

        if ($roleCodes->contains('client')) {
            return 'client';
        }

        return $roleCodes->first() ?? 'client';
    }

    private function resolverColumnasLegacy(array $roleCodes, string $primaryRoleCode): array
    {
        $roleCodes = array_values(array_unique(array_filter($roleCodes, fn ($code) => is_string($code) && $code !== '')));

        $baseRole = collect(['admin', 'provider', 'client'])
            ->first(fn ($role) => in_array($role, $roleCodes, true))
            ?? (in_array($primaryRoleCode, self::BASE_ROLES, true) ? $primaryRoleCode : 'client');

        $operationalRole = ! in_array($primaryRoleCode, self::BASE_ROLES, true)
            ? $primaryRoleCode
            : collect($roleCodes)->first(fn ($role) => ! in_array($role, self::BASE_ROLES, true));

        return [$baseRole, $operationalRole];
    }
};
