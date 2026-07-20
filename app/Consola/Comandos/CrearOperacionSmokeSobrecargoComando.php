<?php

namespace App\Consola\Comandos;

use App\Dominio\Sobrecargo\CrewAssignmentStatus;
use App\Modelos\Aeronave;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\ChecklistOperacion;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CrearOperacionSmokeSobrecargoComando extends Command
{
    public const KEY = 'crew-smoke-operation-v1';

    protected $signature = 'crew:create-smoke-operation {--dry-run} {--email=sobrecargo@test.com} {--admin-email=admin@test.com}';

    protected $description = 'Crea una operacion persistente e idempotente para el smoke test de sobrecargo';

    public function handle(): int
    {
        $existing = SolicitudVuelo::query()->where('idempotency_key', self::KEY)->first();
        if ($this->option('dry-run')) {
            $this->info($existing ? "Ya existe flight_request #{$existing->id}; no se escribiria nada." : 'Se crearian usuario(s) reutilizables, solicitud, operacion, asignacion y checklists identificados como smoke test.');

            return self::SUCCESS;
        }

        $result = DB::transaction(function () use ($existing) {
            $crew = Usuario::query()->firstOrCreate(
                ['email' => (string) $this->option('email')],
                ['name' => 'Sobrecargo Smoke Test', 'password' => Hash::make('SmokeTest!2026'), 'role' => Usuario::ROLE_SOBRECARGO, 'operational_role' => Usuario::ROLE_SOBRECARGO, 'status' => 'active', 'email_verified_at' => now()],
            );
            $admin = Usuario::query()->firstOrCreate(
                ['email' => (string) $this->option('admin-email')],
                ['name' => 'Admin Smoke Test', 'password' => Hash::make('SmokeTest!2026'), 'role' => Usuario::ROLE_ADMIN, 'operational_role' => Usuario::ROLE_ADMIN, 'status' => 'active', 'email_verified_at' => now()],
            );
            $provider = Proveedor::query()->firstOrFail();
            $aircraft = Aeronave::query()->where('provider_id', $provider->id)->firstOrFail();
            $client = Usuario::query()->where('role', Usuario::ROLE_CLIENT)->firstOrFail();
            $flight = $existing ?: SolicitudVuelo::query()->create([
                'idempotency_key' => self::KEY, 'client_id' => $client->id,
                'assigned_provider_id' => $provider->id, 'assigned_aircraft_id' => $aircraft->id,
                'origin' => 'MMMX', 'destination' => 'MMUN', 'departure_datetime' => now()->addDays(3)->setTime(10, 0),
                'return_datetime' => now()->addDays(3)->setTime(18, 0), 'passengers' => 3,
                'trip_type' => 'round_trip', 'status' => 'confirmada', 'workflow_status' => 'flight_confirmed',
                'notes' => '[CREW_SMOKE_TEST] Datos controlados; se pueden eliminar con crew:delete-smoke-operation.',
            ]);
            $operation = Operacion::query()->firstOrCreate(
                ['flight_request_id' => $flight->id],
                ['provider_id' => $provider->id, 'aircraft_id' => $aircraft->id, 'sobrecargo_user_id' => $crew->id, 'status' => 'tracking_live', 'crew_status' => CrewAssignmentStatus::PENDING_CONFIRMATION, 'crew_notes' => '[CREW_SMOKE_TEST]'],
            );
            $assignment = AsignacionSobrecargo::query()->firstOrCreate(
                ['operation_id' => $operation->id, 'sobrecargo_user_id' => $crew->id],
                ['role' => 'sobrecargo', 'status' => CrewAssignmentStatus::PENDING_CONFIRMATION, 'assigned_by' => $admin->id, 'assigned_at' => now(), 'response_deadline' => now()->addDay(), 'presentation_time' => now()->addDays(3)->setTime(9, 0)],
            );
            foreach (['preparation', 'preflight', 'postflight'] as $type) {
                $checklist = ChecklistOperacion::query()->firstOrCreate(['operation_id' => $operation->id, 'sobrecargo_user_id' => $crew->id, 'type' => $type], ['status' => 'pending']);
                $checklist->items()->firstOrCreate(['code' => "smoke_{$type}"], ['category' => 'smoke', 'label' => 'Validacion persistente de '.$type, 'status' => 'pending', 'is_required' => true, 'is_critical' => false, 'is_completed' => false]);
            }
            $operation->timeline()->firstOrCreate(['status' => 'smoke_test_created'], ['title' => 'Operacion smoke creada', 'description' => '[CREW_SMOKE_TEST]', 'created_by' => $admin->id]);

            return compact('flight', 'operation', 'assignment', 'crew', 'admin');
        }, 3);

        $this->table(['Dato', 'Valor'], [
            ['flightRequestId', $result['flight']->id], ['operationId', $result['operation']->id], ['assignmentId', $result['assignment']->id],
            ['sobrecargo', $result['crew']->email], ['administrador', $result['admin']->email], ['estado', $result['assignment']->status],
        ]);
        $this->info('Siguiente paso: iniciar sesion como sobrecargo, aceptar y recorrer el flujo; vuelve a ejecutar este comando para comprobar idempotencia.');

        return self::SUCCESS;
    }
}
