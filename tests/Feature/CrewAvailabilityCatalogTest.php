<?php

namespace Tests\Feature;

use App\Modelos\CatalogoDisponibilidadEstatus;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrewAvailabilityCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_repairs_missing_default_and_get_and_post_work(): void
    {
        $this->seed();
        $crew = Usuario::where('email', 'sobrecargo@redaviation.test')->firstOrFail();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $crew->email, 'password' => 'password'])->assertOk()->json('token');
        $this->withToken($token);
        CatalogoDisponibilidadEstatus::where('clave', 'POR_CONFIRMAR')->delete();
        $url = '/api/v1/sobrecargo/availability?from=2026-09-10&to=2026-09-11';
        $this->getJson($url)->assertNotFound();
        $existing = CatalogoDisponibilidadEstatus::where('clave', 'DISPONIBLE')->firstOrFail();
        $existing->update(['nombre' => 'Nombre personalizado']);
        $original = $existing->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_09_07_180000_completar_catalogo_disponibilidad_estatus.php');
        $migration->up();
        $migration->up();
        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertSame(1, CatalogoDisponibilidadEstatus::where('clave', 'POR_CONFIRMAR')->count());
        $response = $this->getJson($url)->assertOk();
        $this->assertCount(2, $response->json('availability'));
        $this->getJson('/api/v1/sobrecargo/availability/statuses')->assertOk();
        $saved = $this->postJson('/api/v1/sobrecargo/availability', ['fecha' => '2026-09-10', 'status_key' => 'DISPONIBLE', 'comentario' => 'Prueba'])->assertCreated();
        $id = $saved->json('availability.0.id');
        $this->assertNotNull($id);
        $this->getJson("/api/v1/sobrecargo/availability/$id/bitacora")->assertOk()->assertJsonCount(1, 'bitacora');
        $this->getJson($url)->assertOk();
    }
}
