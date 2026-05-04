<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name', 100);
                $table->string('type', 50)->default('business')->index();
                $table->string('description', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->boolean('is_primary')->default(false)->index();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'role_id']);
                $table->index(['user_id', 'is_primary']);
            });
        }

        $now = now();

        DB::table('roles')->upsert([
            [
                'code' => 'client',
                'name' => 'Cliente',
                'type' => 'base',
                'description' => 'Usuario que cotiza y reserva vuelos.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'provider',
                'name' => 'Proveedor',
                'type' => 'base',
                'description' => 'Operador o proveedor que publica aeronaves y cotiza.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'admin',
                'name' => 'Administrador',
                'type' => 'system',
                'description' => 'Usuario con control total del sistema.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'sobrecargo',
                'name' => 'Sobrecargo',
                'type' => 'operational',
                'description' => 'Usuario operativo asignado a servicios y checklists.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code'], ['name', 'type', 'description', 'is_active', 'updated_at']);

        $rolesByCode = DB::table('roles')->pluck('id', 'code');

        DB::table('users')
            ->select(['id', 'role', 'operational_role'])
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($rolesByCode, $now) {
                $rows = [];

                foreach ($users as $user) {
                    $roleCodes = collect([$user->role, $user->operational_role])
                        ->filter(fn ($code) => is_string($code) && $code !== '' && isset($rolesByCode[$code]))
                        ->unique()
                        ->values();

                    if ($roleCodes->isEmpty()) {
                        $roleCodes = collect(['client']);
                    }

                    $primaryRole = $user->operational_role ?: $user->role ?: 'client';

                    foreach ($roleCodes as $code) {
                        $rows[] = [
                            'user_id' => $user->id,
                            'role_id' => $rolesByCode[$code],
                            'is_primary' => $code === $primaryRole,
                            'assigned_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('user_roles')->upsert(
                        $rows,
                        ['user_id', 'role_id'],
                        ['is_primary', 'assigned_at', 'updated_at']
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
    }
};
