<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\DisponibilidadAeronave;
use App\Modelos\DocumentoAeronave;
use App\Modelos\ImagenAeronave;
use App\Modelos\Proveedor;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use App\Servicios\Aeronaves\AircraftStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAircraftFleetPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_aircraft_fleet_endpoint_preserves_contract_and_avoids_n_plus_one_queries(): void
    {
        $adminToken = $this->createAdminToken();
        $provider = $this->createApprovedProvider();
        $this->createFleetDataset($provider, 6, 5);

        $legacy = $this->measureLegacyFleetHydration(6);
        $optimizedHydration = $this->measureOptimizedFleetHydration(6);
        $optimized = $this->measureQueriesDuring(
            fn () => $this->withToken($adminToken)->getJson('/api/v1/admin/fleet/aircraft?per_page=6')
        );

        $optimized['result']
            ->assertOk()
            ->assertJsonCount(6, 'aircraft.data')
            ->assertJsonStructure([
                'success',
                'aircraft' => [
                    'data' => [[
                        'id',
                        'provider_id',
                        'registration',
                        'documents',
                        'images',
                        'suscripcionesAeronave',
                        'provider_display_name',
                        'provider_name',
                        'provider' => [
                            'id',
                            'company_name',
                            'commercial_name',
                            'display_name',
                        ],
                        'aircraft_state' => [
                            'review',
                            'documents',
                            'payment',
                            'billing',
                            'operation',
                            'pricing',
                            'activation',
                            'ready_to_quote',
                            'ready_to_book',
                        ],
                        'ready_to_quote',
                        'ready_to_book',
                    ]],
                ],
            ]);

        $this->assertGreaterThan(
            $optimizedHydration['count'],
            $legacy['count'],
            sprintf(
                'El flujo legado deberia ejecutar mas queries. Legado: %d. Hidratacion optimizada: %d.',
                $legacy['count'],
                $optimizedHydration['count'],
            )
        );
        $this->assertGreaterThanOrEqual(
            6,
            $legacy['count'] - $optimizedHydration['count'],
            sprintf(
                'La mejora esperada debe eliminar al menos una query por aeronave. Legado: %d. Hidratacion optimizada: %d.',
                $legacy['count'],
                $optimizedHydration['count'],
            )
        );
        $this->assertLessThanOrEqual(
            $optimizedHydration['count'] + 4,
            $optimized['count'],
            sprintf(
                'El endpoint real no deberia alejarse materialmente de la hidratacion optimizada. Endpoint: %d. Hidratacion: %d.',
                $optimized['count'],
                $optimizedHydration['count'],
            )
        );
    }

    public function test_admin_aircraft_fleet_query_count_does_not_scale_linearly_with_more_aircraft(): void
    {
        $adminToken = $this->createAdminToken();
        $provider = $this->createApprovedProvider();
        $runs = [];
        $datasetSizes = [5, 25, 50];
        $created = 0;

        foreach ($datasetSizes as $size) {
            $toCreate = $size - $created;
            $this->createFleetDataset($provider, $toCreate, 4, $created);
            $created = $size;

            $runs[$size] = $this->measureQueriesDuring(
                fn () => $this->withToken($adminToken)->getJson("/api/v1/admin/fleet/aircraft?per_page={$size}")
            );
        }

        $baseline = $runs[5]['count'];

        foreach ([25, 50] as $size) {
            $this->assertLessThanOrEqual(
                2,
                abs($runs[$size]['count'] - $baseline),
                sprintf(
                    'La cantidad de queries no debe crecer proporcionalmente con la flota. Base(5): %d. Corrida(%d): %d.',
                    $baseline,
                    $size,
                    $runs[$size]['count'],
                )
            );
        }
    }

    private function createAdminToken(): string
    {
        $admin = Usuario::factory()->create([
            'role' => Usuario::ROLE_ADMIN,
            'operational_role' => Usuario::ROLE_ADMIN,
            'status' => 'active',
            'email' => 'admin.fleet@test.com',
        ]);

        return TokenApi::issue($admin);
    }

    private function createApprovedProvider(): Proveedor
    {
        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'operational_role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
        ]);

        return Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Skygroup Fleet',
            'commercial_name' => 'Skygroup Fleet',
            'approval_status' => 'approved',
            'admin_validation_status' => 'approved',
            'status' => 'approved',
            'access_enabled' => true,
        ]);
    }

    private function createFleetDataset(Proveedor $provider, int $aircraftCount, int $availabilityRowsPerAircraft, int $startOffset = 0): void
    {
        $availableColumns = array_flip(Schema::getColumnListing('aircraft'));

        for ($index = 0; $index < $aircraftCount; $index++) {
            $sequence = $startOffset + $index + 1;

            $aircraftAttributes = array_filter([
                'provider_id' => $provider->id,
                'model' => 'Citation XLS '.$sequence,
                'manufacturer' => 'Cessna',
                'category' => 'Light Jet',
                'model_year' => 2020,
                'registration' => sprintf('XA-FLT%02d', $sequence),
                'capacity' => 8,
                'base_airport' => 'MMMX',
                'range_km' => 3200,
                'speed_kmh' => 700,
                'hourly_rate' => 5200,
                'minimum_hours' => 2,
                'currency' => 'USD',
                'status' => 'active',
                'is_active' => isset($availableColumns['is_active']) ? true : null,
                'operational_status' => isset($availableColumns['operational_status']) ? 'active' : null,
                'validation_status' => isset($availableColumns['validation_status']) ? 'approved' : null,
                'approved_at' => isset($availableColumns['approved_at']) ? now()->subDay() : null,
            ], static fn ($value) => $value !== null);

            $aircraft = Aeronave::query()->create($aircraftAttributes);

            foreach (['airworthiness', 'registration', 'insurance', 'maintenance'] as $documentType) {
                DocumentoAeronave::query()->create([
                    'aircraft_id' => $aircraft->id,
                    'provider_id' => $provider->id,
                    'document_type' => $documentType,
                    'document_name' => $documentType.' document',
                    'document_url' => sprintf('https://example.test/%s/%d.pdf', $documentType, $aircraft->id),
                    'status' => 'approved',
                    'verified_by_admin' => true,
                    'expires_at' => now()->addMonths(6),
                ]);
            }

            ImagenAeronave::query()->create([
                'aircraft_id' => $aircraft->id,
                'kind' => 'gallery',
                'title' => 'Exterior '.$sequence,
                'image_url' => sprintf('https://example.test/aircraft/%d/exterior.jpg', $aircraft->id),
                'sort_order' => 1,
                'is_main' => true,
                'visible_to_client' => true,
            ]);

            for ($availabilityIndex = 0; $availabilityIndex < $availabilityRowsPerAircraft; $availabilityIndex++) {
                DisponibilidadAeronave::query()->create([
                    'aircraft_id' => $aircraft->id,
                    'start_datetime' => now()->addDays($availabilityIndex + 1)->startOfDay(),
                    'end_datetime' => now()->addDays($availabilityIndex + 1)->endOfDay(),
                    'status' => $availabilityIndex % 2 === 0 ? 'available' : 'blocked',
                    'notes' => 'Availability slot '.$availabilityIndex,
                ]);
            }
        }
    }

    private function measureLegacyFleetHydration(int $perPage): array
    {
        return $this->measureQueriesDuring(function () use ($perPage) {
            $fleet = $this->buildFleetPaginator($perPage);
            $service = app(AircraftStateService::class);

            $fleet->setCollection(
                $fleet->getCollection()->map(function (Aeronave $aircraft) use ($service) {
                    $service->evaluate($aircraft->loadMissing(['provider', 'documents', 'images', 'availability', 'baseAirport']));

                    return $aircraft;
                })
            );

            return $fleet;
        });
    }

    private function measureOptimizedFleetHydration(int $perPage): array
    {
        return $this->measureQueriesDuring(function () use ($perPage) {
            $fleet = $this->buildFleetPaginator($perPage);
            $service = app(AircraftStateService::class);

            $fleet->setCollection(
                $fleet->getCollection()->map(function (Aeronave $aircraft) use ($service) {
                    $service->evaluate($aircraft);

                    return $aircraft;
                })
            );

            return $fleet;
        });
    }

    private function buildFleetPaginator(int $perPage)
    {
        $requestedColumns = [
            'id',
            'provider_id',
            'model',
            'manufacturer',
            'category',
            'model_year',
            'registration',
            'capacity',
            'base_airport',
            'base_airport_id',
            'range_km',
            'speed_kmh',
            'coverage',
            'amenities',
            'hourly_rate',
            'airport_expenses_usd',
            'minimum_hours',
            'minimum_route_price',
            'climb_descent_minutes',
            'operational_cost',
            'fuel_burn_gph',
            'engine_reserve_rate',
            'insurance_rate',
            'maintenance_rate',
            'crew_rate',
            'repositioning_fee',
            'overnight_fee',
            'currency',
            'status',
            'is_active',
            'operational_status',
            'validation_status',
            'activated_at',
            'approved_at',
            'billing_status',
            'billing_plan_id',
            'subscription_status',
            'subscription_started_at',
            'subscription_ends_at',
            'last_payment_at',
            'security_filter',
            'security_score',
            'airworthiness_status',
            'last_maintenance_at',
            'engine_run_at',
            'captain_training_at',
            'lodging_location',
            'client_fbo',
            'dispatch_center',
            'dispatch_notes',
            'security_notes',
            'created_at',
            'updated_at',
        ];

        $availableColumns = array_flip(Schema::getColumnListing('aircraft'));
        $selectedColumns = array_values(array_filter($requestedColumns, fn (string $column) => isset($availableColumns[$column])));

        return Aeronave::query()
            ->select($selectedColumns)
            ->with([
                'provider:id,user_id,company_name,commercial_name,approval_status,admin_validation_status,status,access_enabled',
                'provider.user:id,name',
                'provider.user.profile:id,user_id,company_name',
                'baseAirport:id,icao,iata',
                'documents',
                'images',
                'suscripcionesAeronave' => fn ($query) => $query->where('status', 'active')->with('plan')->latest('id'),
            ])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @return array{result:mixed,count:int,elapsed_ms:float}
     */
    private function measureQueriesDuring(callable $callback): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $startedAt = microtime(true);
        $result = $callback();
        $elapsedMs = round((microtime(true) - $startedAt) * 1000, 2);

        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();
        $connection->flushQueryLog();

        return [
            'result' => $result,
            'count' => count($queries),
            'elapsed_ms' => $elapsedMs,
        ];
    }
}
