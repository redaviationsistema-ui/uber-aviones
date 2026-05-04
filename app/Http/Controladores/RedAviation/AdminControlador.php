<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\BanderaAntiBroker;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\Rol;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Suscripcion;
use App\Modelos\Usuario;
use App\Servicios\RedAviation\KpiSaasServicio;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminControlador extends ControladorBase
{
    public function __construct(private readonly KpiSaasServicio $kpiSaasServicio)
    {
    }

    public function dashboard()
    {
        return $this->ok(['kpis' => $this->kpiSaasServicio->resumen()]);
    }

    public function users()
    {
        return $this->ok([
            'users' => Usuario::with(['roles', 'profile', 'provider'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function roles()
    {
        return $this->ok([
            'roles' => Rol::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::in([
                Usuario::ROLE_CLIENT,
                Usuario::ROLE_PROVIDER,
                Usuario::ROLE_ADMIN,
                Usuario::ROLE_SOBRECARGO,
            ])],
            'status' => ['sometimes', 'in:active,inactive,blocked'],
        ]);

        $plainPassword = $data['password'] ?? Str::password(12);
        $user = Usuario::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($plainPassword),
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        $this->syncUserRoles($user, $data['role']);
        $this->ensureProviderRecord($user);
        $this->writeAudit($request, 'admin_user_created', 'admin_users', sprintf(
            'Admin creo al usuario %s con rol %s.',
            $user->email,
            $data['role']
        ));

        return $this->ok([
            'user' => $user->fresh(['roles', 'profile', 'provider']),
            'temporary_password' => $plainPassword,
        ], 201);
    }

    public function updateUser(Request $request, Usuario $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['sometimes', Rule::in([
                Usuario::ROLE_CLIENT,
                Usuario::ROLE_PROVIDER,
                Usuario::ROLE_ADMIN,
                Usuario::ROLE_SOBRECARGO,
            ])],
            'status' => ['sometimes', 'in:active,inactive,blocked'],
        ]);

        $user->update(collect($data)->except('role')->all());

        if (isset($data['role'])) {
            $this->syncUserRoles($user, $data['role']);
            $this->ensureProviderRecord($user);
        }

        $this->writeAudit($request, 'admin_user_updated', 'admin_users', sprintf(
            'Admin actualizo al usuario %s.',
            $user->email
        ));

        return $this->ok([
            'user' => $user->fresh(['roles', 'profile', 'provider']),
        ]);
    }

    public function destroyUser(Request $request, Usuario $user)
    {
        if ($request->user()?->is($user)) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes eliminar tu propio usuario administrador.',
            ], 422);
        }

        $email = $user->email;
        $user->delete();

        $this->writeAudit($request, 'admin_user_deleted', 'admin_users', sprintf(
            'Admin elimino al usuario %s.',
            $email
        ));

        return $this->ok([
            'message' => 'Usuario eliminado correctamente.',
        ]);
    }

    public function blockUser(Request $request, Usuario $user)
    {
        $user->update(['status' => 'blocked']);

        $this->writeAudit($request, 'admin_user_blocked', 'admin_users', sprintf(
            'Admin bloqueo al usuario %s.',
            $user->email
        ));

        return $this->ok([
            'user' => $user->fresh(['roles', 'profile', 'provider']),
        ]);
    }

    public function activateUser(Request $request, Usuario $user)
    {
        $user->update(['status' => 'active']);

        $this->writeAudit($request, 'admin_user_activated', 'admin_users', sprintf(
            'Admin activo al usuario %s.',
            $user->email
        ));

        return $this->ok([
            'user' => $user->fresh(['roles', 'profile', 'provider']),
        ]);
    }

    public function resetUserPassword(Request $request, Usuario $user)
    {
        $plainPassword = Str::password(12);
        $user->forceFill([
            'password' => Hash::make($plainPassword),
        ])->save();

        $this->writeAudit($request, 'admin_user_password_reset', 'admin_users', sprintf(
            'Admin reinicio la contrasena del usuario %s.',
            $user->email
        ));

        return $this->ok([
            'message' => 'Contrasena reiniciada correctamente.',
            'temporary_password' => $plainPassword,
            'user' => $user->fresh(['roles', 'profile', 'provider']),
        ]);
    }

    public function operators()
    {
        return $this->ok(['operators' => Proveedor::with('user')->latest()->paginate(20)]);
    }

    public function sobrecargos()
    {
        return $this->ok([
            'sobrecargos' => Usuario::whereHas('roles', fn ($query) => $query->where('code', Usuario::ROLE_SOBRECARGO))
                ->latest()
                ->paginate(20),
        ]);
    }

    public function requests()
    {
        return $this->ok(['requests' => SolicitudVuelo::with(['client', 'matches.aircraft'])->latest()->paginate(20)]);
    }

    public function assign(Request $request, SolicitudVuelo $flightRequest)
    {
        $data = $request->validate([
            'provider_id' => ['required', 'exists:providers,id'],
            'aircraft_id' => ['required', 'exists:aircraft,id'],
            'sobrecargo_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $operacion = Operacion::updateOrCreate(
            ['flight_request_id' => $flightRequest->id],
            [
                'provider_id' => $data['provider_id'],
                'aircraft_id' => $data['aircraft_id'],
                'sobrecargo_user_id' => $data['sobrecargo_user_id'] ?? null,
                'status' => 'operador_asignado',
            ]
        );

        $operacion->timeline()->create([
            'status' => 'operador_asignado',
            'title' => 'Asignacion manual',
            'description' => 'Admin Red Aviation realizo el matching manual.',
            'created_by' => $request->user()->id,
        ]);

        $flightRequest->update(['workflow_status' => 'operador_asignado']);

        return $this->ok(['operation' => $operacion->load('timeline')]);
    }

    public function subscriptions()
    {
        return $this->ok(['subscriptions' => Suscripcion::with(['user', 'plan'])->latest()->paginate(20)]);
    }

    public function kpis()
    {
        return $this->ok(['kpis' => $this->kpiSaasServicio->resumen()]);
    }

    public function antiBrokerFlags()
    {
        return $this->ok(['flags' => BanderaAntiBroker::latest()->paginate(20)]);
    }

    public function dataTransferSchema(Request $request)
    {
        $connection = $this->resolveConnection($request->query('connection'));
        $tables = collect(Schema::connection($connection)->getTableListing())
            ->map(fn (string $table) => [
                'name' => $table,
                'columns' => Schema::connection($connection)->getColumnListing($table),
            ])
            ->values();

        return $this->ok([
            'connection' => $connection,
            'tables' => $tables,
        ]);
    }

    public function importDataTransfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'connection' => ['required', 'string'],
            'resource' => ['required', 'string'],
            'mode' => ['required', 'in:append,replace'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
        ]);

        $connection = $this->resolveConnection($data['connection']);
        $table = $this->resolveTable($connection, $data['resource']);
        $columns = Schema::connection($connection)->getColumnListing($table);
        $allowedColumns = array_values(array_diff($columns, ['id']));
        $rows = $this->parseSpreadsheetFile($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalExtension());

        if (empty($rows)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo no contiene filas para importar.',
            ], 422);
        }

        $normalizedRows = collect($rows)
            ->map(fn (array $row) => $this->applyTableAliases($table, $row))
            ->map(function (array $row) use ($allowedColumns) {
                $filtered = [];

                foreach ($allowedColumns as $column) {
                    if (array_key_exists($column, $row)) {
                        $filtered[$column] = $row[$column] === '' ? null : $row[$column];
                    }
                }

                return $filtered;
            })
            ->filter(fn (array $row) => ! empty($row))
            ->values()
            ->all();

        if (empty($normalizedRows)) {
            return response()->json([
                'success' => false,
                'message' => 'Ninguna columna del archivo coincide con la tabla seleccionada.',
            ], 422);
        }

        $missingColumns = $this->detectMissingRequiredColumns($table, $normalizedRows);

        if ($missingColumns !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Faltan columnas obligatorias para importar en la tabla seleccionada.',
                'missing_columns' => $missingColumns,
            ], 422);
        }

        try {
            DB::connection($connection)->transaction(function () use ($connection, $table, $data, $normalizedRows) {
                $query = DB::connection($connection)->table($table);

                if ($data['mode'] === 'replace') {
                    $query->delete();
                }

                foreach (array_chunk($normalizedRows, 500) as $chunk) {
                    $query->insert($chunk);
                }
            });
        } catch (QueryException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'La base de datos rechazo la importacion. Revisa columnas obligatorias, duplicados o tipos de dato.',
                'detail' => $exception->getMessage(),
            ], 422);
        }

        $this->writeAudit($request, 'data_transfer_import', 'admin_data_transfer', sprintf(
            'Importacion a %s.%s con %d filas',
            $connection,
            $table,
            count($normalizedRows)
        ));

        return $this->ok([
            'summary' => [
                'connection' => $connection,
                'table' => $table,
                'inserted_rows' => count($normalizedRows),
                'message' => sprintf('Se importaron %d filas en %s.%s.', count($normalizedRows), $connection, $table),
            ],
        ]);
    }

    public function exportDataTransfer(Request $request): StreamedResponse|JsonResponse|\Illuminate\Http\Response
    {
        $connection = $this->resolveConnection($request->query('connection'));
        $table = $this->resolveTable($connection, (string) $request->query('resource'));
        $format = strtolower((string) $request->query('format', 'xlsx'));
        $columns = Schema::connection($connection)->getColumnListing($table);
        $rows = DB::connection($connection)->table($table)->limit(5000)->get($columns);

        if ($format === 'csv') {
            $filename = sprintf('%s-%s.csv', $table, now()->format('Y-m-d'));

            return response()->streamDownload(function () use ($columns, $rows) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, $columns);

                foreach ($rows as $row) {
                    fputcsv($handle, array_map(fn (string $column) => $row->{$column}, $columns));
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        if ($format === 'xlsx') {
            $sheetRows = [$columns];

            foreach ($rows as $row) {
                $sheetRows[] = array_map(fn (string $column) => $row->{$column}, $columns);
            }

            $binary = (string) SimpleXLSXGen::fromArray($sheetRows, $table);
            $filename = sprintf('%s-%s.xlsx', $table, now()->format('Y-m-d'));

            return response($binary, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Formato de exportacion no soportado. Usa csv o xlsx.',
        ], 422);
    }

    private function resolveConnection(?string $connection): string
    {
        $requested = $connection ?: config('database.default');
        $allowed = ['pgsql', 'sqlite', 'sqlite-test'];

        if (! in_array($requested, $allowed, true)) {
            abort(response()->json([
                'success' => false,
                'message' => 'La conexion solicitada no esta permitida para importaciones.',
            ], 422));
        }

        if ($requested === 'sqlite-test') {
            config(['database.connections.sqlite-test' => [
                'driver' => 'sqlite',
                'database' => database_path('test.sqlite'),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
        }

        return $requested;
    }

    private function resolveTable(string $connection, string $table): string
    {
        $availableTables = Schema::connection($connection)->getTableListing();

        if (! in_array($table, $availableTables, true)) {
            abort(response()->json([
                'success' => false,
                'message' => 'La tabla seleccionada no existe en la conexion indicada.',
            ], 422));
        }

        return $table;
    }

    private function parseSpreadsheetFile(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        return match ($extension) {
            'csv', 'txt' => $this->parseCsvFile($path),
            'xlsx' => $this->parseXlsxFile($path),
            'xls' => $this->parseXlsFile($path),
            default => [],
        };
    }

    private function parseCsvFile(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            return [];
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            fclose($handle);
            return [];
        }

        $headers = $this->normalizeHeaders($headers);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($row, count($headers), null));
        }

        fclose($handle);

        return $rows;
    }

    private function parseXlsxFile(string $path): array
    {
        $xlsx = SimpleXLSX::parse($path);

        if (! $xlsx) {
            return [];
        }

        return $this->rowsToAssociative($xlsx->rows());
    }

    private function parseXlsFile(string $path): array
    {
        $xls = SimpleXLS::parse($path);

        if (! $xls) {
            return [];
        }

        return $this->rowsToAssociative($xls->rows());
    }

    private function rowsToAssociative(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $headers = $this->normalizeHeaders((array) array_shift($rows));
        $output = [];

        foreach ($rows as $row) {
            $row = array_map(fn ($value) => is_string($value) ? trim($value) : $value, (array) $row);

            if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }

            $output[] = array_combine($headers, array_pad($row, count($headers), null));
        }

        return $output;
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $header = (string) $header;
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            $header = strtolower(trim($header));
            $header = preg_replace('/\s+/', '_', $header);

            return $header;
        }, $headers);
    }

    private function applyTableAliases(string $table, array $row): array
    {
        if ($table === 'airports') {
            if (! array_key_exists('icao', $row) && array_key_exists('icao_code', $row)) {
                $row['icao'] = $row['icao_code'];
            }

            if (! array_key_exists('iata', $row) && array_key_exists('iata_code', $row)) {
                $row['iata'] = $row['iata_code'];
            }

            if (! array_key_exists('status', $row) || $row['status'] === '' || $row['status'] === null) {
                $row['status'] = 'active';
            }
        }

        return $row;
    }

    private function detectMissingRequiredColumns(string $table, array $rows): array
    {
        $requiredColumns = match ($table) {
            'airports' => ['icao', 'name'],
            default => [],
        };

        if ($requiredColumns === []) {
            return [];
        }

        $missing = [];

        foreach ($requiredColumns as $column) {
            $hasValue = collect($rows)->contains(function (array $row) use ($column) {
                return array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '';
            });

            if (! $hasValue) {
                $missing[] = $column;
            }
        }

        return $missing;
    }

    private function syncUserRoles(Usuario $user, string $selectedRole): void
    {
        $roles = $selectedRole === Usuario::ROLE_SOBRECARGO
            ? [Usuario::ROLE_CLIENT, Usuario::ROLE_SOBRECARGO]
            : [$selectedRole];

        $user->syncRoles($roles, $selectedRole);
    }

    private function ensureProviderRecord(Usuario $user): void
    {
        if (! $user->hasRole(Usuario::ROLE_PROVIDER)) {
            return;
        }

        $user->provider()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => $user->name,
                'commercial_name' => $user->name,
                'approval_status' => 'pending',
            ]
        );
    }
}


