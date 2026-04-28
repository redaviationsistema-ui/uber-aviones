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
            ['icao' => 'MMMX', 'iata' => 'MEX', 'name' => 'Aeropuerto Internacional Benito Juarez', 'city' => 'Ciudad de Mexico', 'country' => 'Mexico'],
            ['icao' => 'MMUN', 'iata' => 'CUN', 'name' => 'Aeropuerto Internacional de Cancun', 'city' => 'Cancun', 'country' => 'Mexico'],
            ['icao' => 'MMTO', 'iata' => 'TLC', 'name' => 'Aeropuerto Internacional de Toluca', 'city' => 'Toluca', 'country' => 'Mexico'],
        ] as $airport) {
            Aeropuerto::firstOrCreate(['icao' => $airport['icao']], $airport + [
                'icao_code' => $airport['icao'],
                'iata_code' => $airport['iata'],
                'status' => 'active',
            ]);
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

        $providerUsuario = Usuario::firstOrCreate(
            ['email' => 'proveedor@privateflights.test'],
            [
                'name' => 'Proveedor Demo',
                'password' => 'password',
                'phone' => '+52 55 0000 0000',
                'role' => 'provider',
                'status' => 'active',
            ]
        );

        $provider = Proveedor::firstOrCreate(
            ['user_id' => $providerUsuario->id],
            [
                'company_name' => 'Private Jets Demo SA de CV',
                'commercial_name' => 'Private Jets Demo',
                'approval_status' => 'approved',
            ]
        );

        $aircraft = Aeronave::firstOrCreate(
            ['registration' => 'XA-DEMO'],
            [
                'provider_id' => $provider->id,
                'model' => 'Citation Latitude',
                'capacity' => 8,
                'base_airport' => 'MMMX',
                'range_km' => 5000,
                'speed_kmh' => 826,
                'hourly_rate' => 4800,
                'currency' => 'USD',
                'status' => 'active',
            ]
        );

        DisponibilidadAeronave::firstOrCreate(
            [
                'aircraft_id' => $aircraft->id,
                'start_datetime' => now()->addDay()->startOfDay(),
            ],
            [
                'end_datetime' => now()->addDays(30)->endOfDay(),
                'status' => 'available',
            ]
        );

        Usuario::firstOrCreate(
            ['email' => 'cliente@privateflights.test'],
            [
                'name' => 'Cliente Demo',
                'password' => 'password',
                'phone' => '+52 55 1111 1111',
                'role' => 'client',
                'status' => 'active',
            ]
        );

        Usuario::firstOrCreate(
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

        $admin->profile()->firstOrCreate(['country' => 'Mexico', 'city' => 'CDMX']);
    }
}
