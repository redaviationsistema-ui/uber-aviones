<?php

namespace Database\Seeders;

use App\Modelos\Aeronave;
use App\Modelos\DisponibilidadAeronave;
use App\Modelos\Aeropuerto;
use App\Modelos\Plan;
use App\Modelos\Proveedor;
use App\Modelos\ConfiguracionSistema;
use App\Modelos\Usuario;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Usuario::firstOrCreate(
            ['email' => 'admin@privateflights.test'],
            [
                'name' => 'Administrador',
                'password' => 'password',
                'role' => 'admin',
                'status' => 'active',
            ]
        );
        $admin->syncRoles([Usuario::ROLE_ADMIN], Usuario::ROLE_ADMIN);

        Plan::firstOrCreate(
            ['slug' => 'premium-monthly'],
            [
                'name' => 'Premium Mensual',
                'code' => 'PRO_MENSUAL',
                'description' => 'Plan SaaS mensual Red Aviation.',
                'price' => 299.00,
                'price_monthly' => 299.00,
                'price_yearly' => 2990.00,
                'billing_cycle' => 'monthly',
                'role_target' => 'client',
                'max_requests' => 10,
                'max_aircraft' => 0,
                'max_users' => 1,
                'has_priority' => true,
                'has_concierge' => true,
                'has_reports' => true,
                'is_enterprise' => false,
                'is_active' => true,
                'features' => ['busqueda', 'cotizaciones', 'reservas', 'soporte_prioritario'],
                'status' => 'active',
            ]
        );

        foreach ([
            [
                'icao' => 'MMMX',
                'iata' => 'MEX',
                'name' => 'Aeropuerto Internacional Benito Juarez',
                'city' => 'Ciudad de Mexico',
                'country' => 'Mexico',
                'latitude' => 19.4361000,
                'longitude' => -99.0719000,
                'altitude' => 7316,
                'utc_offset' => -6,
                'timezone' => 'America/Mexico_City',
                'type' => 'airport',
            ],
            [
                'icao' => 'MMUN',
                'iata' => 'CUN',
                'name' => 'Aeropuerto Internacional de Cancun',
                'city' => 'Cancun',
                'country' => 'Mexico',
                'latitude' => 21.0365000,
                'longitude' => -86.8771000,
                'altitude' => 22,
                'utc_offset' => -5,
                'timezone' => 'America/Cancun',
                'type' => 'airport',
            ],
            [
                'icao' => 'MMTO',
                'iata' => 'TLC',
                'name' => 'Aeropuerto Internacional de Toluca',
                'city' => 'Toluca',
                'country' => 'Mexico',
                'latitude' => 19.3371000,
                'longitude' => -99.5660000,
                'altitude' => 8466,
                'utc_offset' => -6,
                'timezone' => 'America/Mexico_City',
                'type' => 'airport',
            ],
        ] as $airport) {
            Aeropuerto::updateOrCreate(['icao' => $airport['icao']], $airport + ['status' => 'active']);
        }

        ConfiguracionSistema::firstOrCreate(
            ['key' => 'platform.business_model'],
            ['group' => 'payments', 'value' => ['mode' => 'saas', 'commission_rate' => 0]]
        );

        Plan::firstOrCreate(
            ['slug' => 'premium-yearly'],
            [
                'name' => 'Premium Anual',
                'code' => 'ELITE_ANUAL',
                'description' => 'Plan SaaS anual Red Aviation.',
                'price' => 2990.00,
                'price_monthly' => 299.00,
                'price_yearly' => 2990.00,
                'billing_cycle' => 'yearly',
                'role_target' => 'client',
                'max_requests' => 50,
                'max_aircraft' => 0,
                'max_users' => 5,
                'has_priority' => true,
                'has_concierge' => true,
                'has_reports' => true,
                'is_enterprise' => true,
                'is_active' => true,
                'features' => ['busqueda', 'cotizaciones', 'reservas', 'soporte_prioritario'],
                'status' => 'active',
            ]
        );

        $providerUsuario = Usuario::updateOrCreate(
            ['email' => 'proveedor@privateflights.test'],
            [
                'name' => 'Proveedor Prueba',
                'password' => 'password',
                'phone' => '+52 55 0000 0000',
                'role' => 'provider',
                'status' => 'active',
            ]
        );
        $providerUsuario->syncRoles([Usuario::ROLE_PROVIDER], Usuario::ROLE_PROVIDER);

        $provider = Proveedor::updateOrCreate(
            ['user_id' => $providerUsuario->id],
            [
                'company_name' => 'Proveedor Prueba',
                'commercial_name' => 'Proveedor Prueba',
                'approval_status' => 'approved',
            ]
        );
        $providerUsuario->forceFill(['provider_id' => $provider->id])->saveQuietly();

        $learjet = Aeronave::updateOrCreate(
            ['registration' => 'XA-LJ45'],
            [
                'provider_id' => $provider->id,
                'model' => 'Learjet 45XR',
                'capacity' => 8,
                'base_airport' => 'MMMX',
                'range_km' => 3700,
                'speed_kmh' => 860,
                'hourly_rate' => 5200,
                'currency' => 'USD',
                'status' => 'active',
            ]
        );

        $hawker = Aeronave::updateOrCreate(
            ['registration' => 'XA-HW8X'],
            [
                'provider_id' => $provider->id,
                'model' => 'Hawker 800XP',
                'capacity' => 8,
                'base_airport' => 'MMMX',
                'range_km' => 4300,
                'speed_kmh' => 745,
                'hourly_rate' => 6100,
                'currency' => 'USD',
                'status' => 'active',
            ]
        );

        DisponibilidadAeronave::firstOrCreate(
            [
                'aircraft_id' => $learjet->id,
                'start_datetime' => now()->addDay()->startOfDay(),
            ],
            [
                'end_datetime' => now()->addDays(30)->endOfDay(),
                'status' => 'available',
            ]
        );

        DisponibilidadAeronave::firstOrCreate(
            [
                'aircraft_id' => $hawker->id,
                'start_datetime' => now()->addDay()->startOfDay(),
            ],
            [
                'end_datetime' => now()->addDays(30)->endOfDay(),
                'status' => 'available',
            ]
        );

        $kevinUsuario = Usuario::updateOrCreate(
            ['email' => 'redaviationsistema@gmail.com'],
            [
                'name' => 'Kevin',
                'password' => 'password',
                'phone' => '+52 55 3333 3333',
                'role' => 'provider',
                'status' => 'active',
            ]
        );
        $kevinUsuario->syncRoles([Usuario::ROLE_PROVIDER], Usuario::ROLE_PROVIDER);

        $kevinProvider = Proveedor::updateOrCreate(
            ['user_id' => $kevinUsuario->id],
            [
                'company_name' => 'Kevin Aviation',
                'commercial_name' => 'Kevin',
                'approval_status' => 'approved',
            ]
        );
        $kevinUsuario->forceFill(['provider_id' => $kevinProvider->id])->saveQuietly();

        $citation = Aeronave::updateOrCreate(
            ['registration' => 'XA-CTLT'],
            [
                'provider_id' => $kevinProvider->id,
                'model' => 'Citation Latitude',
                'capacity' => 9,
                'base_airport' => 'MMTO',
                'range_km' => 5000,
                'speed_kmh' => 826,
                'hourly_rate' => 4800,
                'currency' => 'USD',
                'status' => 'active',
            ]
        );

        DisponibilidadAeronave::firstOrCreate(
            [
                'aircraft_id' => $citation->id,
                'start_datetime' => now()->addDay()->startOfDay(),
            ],
            [
                'end_datetime' => now()->addDays(30)->endOfDay(),
                'status' => 'available',
            ]
        );

        Aeronave::where('registration', 'XA-DEMO')->delete();

        $client = Usuario::firstOrCreate(
            ['email' => 'cliente@privateflights.test'],
            [
                'name' => 'Cliente Demo',
                'password' => 'password',
                'phone' => '+52 55 1111 1111',
                'role' => 'client',
                'status' => 'active',
            ]
        );
        $client->syncRoles([Usuario::ROLE_CLIENT], Usuario::ROLE_CLIENT);

        $sobrecargo = Usuario::firstOrCreate(
            ['email' => 'sobrecargo@redaviation.test'],
            [
                'name' => 'Sobrecargo Demo',
                'password' => 'password',
                'phone' => '+52 55 2222 2222',
                'role' => 'client',
                'operational_role' => 'sobrecargo',
                'status' => 'active',
            ]
        );
        $sobrecargo->syncRoles(
            [Usuario::ROLE_CLIENT, Usuario::ROLE_SOBRECARGO],
            Usuario::ROLE_SOBRECARGO
        );

        $admin->profile()->firstOrCreate(['country' => 'Mexico', 'city' => 'CDMX']);
    }
}
