<?php

namespace Tests\Feature;

use App\Http\Controladores\AeronaveControlador;
use App\Http\Controladores\RedAviation\OperadorControlador;
use App\Modelos\Aeronave;
use App\Servicios\Vuelos\ClimbDescentCategoryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AircraftClimbDescentSourceNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_aeronave_controller_marks_manual_source_when_minutes_are_provided(): void
    {
        $controller = app(AeronaveControlador::class);

        $normalized = $this->invokeNormalizeAircraftInput($controller, [
            'category' => 'Light Jet',
            'climb_descent_minutes' => 52,
        ]);

        $this->assertSame(52, $normalized['climb_descent_minutes']);
        $this->assertSame(Aeronave::CLIMB_DESCENT_SOURCE_MANUAL, $normalized['climb_descent_source']);
    }

    public function test_aeronave_controller_uses_category_default_when_minutes_are_missing(): void
    {
        $controller = app(AeronaveControlador::class);

        $normalized = $this->invokeNormalizeAircraftInput($controller, [
            'category' => 'Mid Jet',
        ]);

        $this->assertSame(35, $normalized['climb_descent_minutes']);
        $this->assertSame(Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT, $normalized['climb_descent_source']);
    }

    public function test_aeronave_controller_partial_edit_does_not_overwrite_manual_source(): void
    {
        $controller = app(AeronaveControlador::class);
        $aircraft = new Aeronave([
            'category' => 'Light Jet',
            'climb_descent_minutes' => 48,
            'climb_descent_source' => Aeronave::CLIMB_DESCENT_SOURCE_MANUAL,
        ]);

        $normalized = $this->invokeNormalizeAircraftInput($controller, [
            'model' => 'Updated Name',
        ], $aircraft);

        $this->assertArrayNotHasKey('climb_descent_minutes', $normalized);
        $this->assertArrayNotHasKey('climb_descent_source', $normalized);
    }

    public function test_partial_edit_preserves_existing_category_default_minutes_when_field_is_omitted(): void
    {
        $controller = app(AeronaveControlador::class);
        $aircraft = new Aeronave([
            'category' => 'Helicoptero',
            'climb_descent_minutes' => 15,
            'climb_descent_source' => Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT,
        ]);

        $normalized = $this->invokeNormalizeAircraftInput($controller, [
            'model' => 'Updated Name',
        ], $aircraft);

        $this->assertArrayNotHasKey('climb_descent_minutes', $normalized);
        $this->assertArrayNotHasKey('climb_descent_source', $normalized);
    }

    public function test_operador_controller_resets_to_category_default_when_zero_is_sent(): void
    {
        $controller = app(OperadorControlador::class);
        $aircraft = new Aeronave([
            'category' => 'Heavy Jet',
            'climb_descent_minutes' => 52,
            'climb_descent_source' => Aeronave::CLIMB_DESCENT_SOURCE_MANUAL,
        ]);

        $normalized = $this->invokeNormalizeAircraftInput($controller, [
            'climb_descent_minutes' => 0,
        ], $aircraft);

        $this->assertSame(45, $normalized['climb_descent_minutes']);
        $this->assertSame(Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT, $normalized['climb_descent_source']);
    }

    public function test_shared_resolver_normalizes_category_aliases_and_uses_official_helicopter_default(): void
    {
        $resolver = app(ClimbDescentCategoryResolver::class);

        $this->assertSame('Helicoptero', $resolver->normalizeCategoryKey(' Helicóptero '));
        $this->assertSame('Turboprop', $resolver->normalizeCategoryKey('TURBO_PROP'));
        $this->assertSame('Light Jet', $resolver->normalizeCategoryKey('LIGHT_JET'));
        $this->assertSame('Mid Jet', $resolver->normalizeCategoryKey('MIDSIZE_JET'));
        $this->assertSame('Heavy Jet', $resolver->normalizeCategoryKey('Ultra Long Range'));
        $this->assertSame(15, $resolver->resolveClimbDescentMinutesForCategory('helicopter'));
    }

    private function invokeNormalizeAircraftInput(object $controller, array $data, ?Aeronave $aircraft = null): array
    {
        $method = new ReflectionMethod($controller, 'normalizeAircraftInput');
        $method->setAccessible(true);

        /** @var array $normalized */
        $normalized = $method->invoke($controller, $data, $aircraft);

        return $normalized;
    }
}
