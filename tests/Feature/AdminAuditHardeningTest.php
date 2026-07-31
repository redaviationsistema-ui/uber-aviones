<?php

namespace Tests\Feature;

use App\Http\Controladores\ControladorBase;
use App\Modelos\RegistroAuditoria;
use App\Modelos\Usuario;
use App\Servicios\Administracion\AdminAuditServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AdminAuditHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_record_skips_expensive_role_resolution_failures_for_non_admin_actors(): void
    {
        $persistedUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'operational_role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => 'audit.client@test.dev',
        ]);

        $actor = Mockery::mock(Usuario::class)->makePartial();
        $actor->id = $persistedUser->id;
        $actor->role = Usuario::ROLE_CLIENT;
        $actor->operational_role = Usuario::ROLE_CLIENT;

        $actor->shouldReceive('effectiveRole')->once()->andReturn(Usuario::ROLE_CLIENT);
        $actor->shouldReceive('relationLoaded')->once()->with('roles')->andReturn(false);
        $actor->shouldReceive('roles')->once()->andThrow(new RuntimeException('Role query timeout'));

        $audit = app(AdminAuditServicio::class)->record(
            actor: $actor,
            action: 'create',
            module: 'red_aviation.flight_requests',
            entity: 'red_aviation.flight_requests',
            entityId: 'fr-test-1',
            after: ['flight_request_id' => 'fr-test-1'],
        );

        $this->assertInstanceOf(RegistroAuditoria::class, $audit);
        $this->assertNull($audit->admin_user_id);
        $this->assertSame($persistedUser->id, $audit->user_id);
        $this->assertDatabaseHas('audit_logs', [
            'id' => $audit->id,
            'admin_user_id' => null,
            'user_id' => $persistedUser->id,
            'action' => 'create',
            'module' => 'red_aviation.flight_requests',
        ]);
    }

    public function test_write_audit_entry_never_throws_when_audit_service_fails(): void
    {
        $controller = new class extends ControladorBase
        {
            public function forwardWriteAuditEntry(...$arguments): void
            {
                $this->writeAuditEntry(...$arguments);
            }
        };

        $auditService = Mockery::mock(AdminAuditServicio::class);
        $auditService->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit storage unavailable'));
        $this->app->instance(AdminAuditServicio::class, $auditService);

        Log::spy();

        $controller->forwardWriteAuditEntry(
            88,
            'create',
            'red_aviation.flight_requests',
            'Solicitud Red Aviation creada.',
            [
                'entity' => 'red_aviation.flight_requests',
                'entity_id' => 'fr-safe-88',
                'after' => ['flight_request_id' => 'fr-safe-88'],
            ],
            '127.0.0.1',
            'PHPUnit',
        );

        Log::shouldHaveReceived('warning')->once()->with(
            'audit_write_failed',
            Mockery::on(fn (array $context) => $context['user_id'] === 88
                && $context['action'] === 'create'
                && $context['module'] === 'red_aviation.flight_requests'
                && $context['entity_id'] === 'fr-safe-88'
                && str_contains($context['message'], 'Audit storage unavailable'))
        );

        $this->assertTrue(true);
    }
}
