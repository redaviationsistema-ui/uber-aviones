<?php

namespace Tests\Feature;

use App\Http\Controladores\RedAviation\SobrecargoControlador;
use App\Modelos\Aeronave;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\ChecklistItem;
use App\Modelos\ChecklistOperacion;
use App\Modelos\LineaTiempoOperacion;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrewChecklistOrderTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $status = 'pending_confirmation'): Operacion
    {
        $this->seed();
        $crew = Usuario::where('email', 'sobrecargo@redaviation.test')->firstOrFail();
        $provider = Proveedor::firstOrFail();
        $flight = SolicitudVuelo::create([
            'client_id' => Usuario::where('email', 'cliente@privateflights.test')->value('id'),
            'origin' => 'MMMX', 'destination' => 'MMUN', 'departure_datetime' => now()->addDay(),
            'passengers' => 3, 'trip_type' => 'one_way', 'status' => 'confirmada', 'workflow_status' => 'flight_confirmed',
        ]);
        $op = Operacion::create([
            'flight_request_id' => $flight->id, 'provider_id' => $provider->id,
            'aircraft_id' => Aeronave::where('provider_id', $provider->id)->value('id'),
            'sobrecargo_user_id' => $crew->id, 'status' => 'confirmed', 'crew_status' => $status,
        ]);
        AsignacionSobrecargo::create([
            'operation_id' => $op->id, 'sobrecargo_user_id' => $crew->id,
            'status' => $status === 'pending_confirmation' ? $status : 'confirmed',
            'assigned_at' => now(), 'response_deadline' => now()->addHour(),
            'presentation_time' => now()->addHour(),
        ]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $crew->email, 'password' => 'password'])->assertOk()->json('token');
        $this->withToken($token);

        return $op;
    }

    public function test_service_ids_remain_in_place_after_completion_refresh_and_reopen(): void
    {
        $this->assertStableOrder('postflight', 'service', ['missing_inventory', 'leftover_catering']);
    }

    public function test_four_cabin_items_remain_in_place_after_completion_refresh_and_reopen(): void
    {
        $this->assertStableOrder('preflight', 'cabin', ['cabin_cleaning', 'seatbelts', 'baggage_secured', 'restroom']);
    }

    public function test_flight_information_remains_in_place_after_completion_refresh_and_reopen(): void
    {
        $this->assertStableOrder('preparation', 'operation', ['route_reviewed', 'aircraft_reviewed']);
    }

    private function assertStableOrder(string $type, string $category, array $codes): void
    {
        $op = $this->fixture(match ($type) {
            'postflight' => 'postflight_pending',
            'preflight' => 'preflight_in_progress',
            default => 'preparation_pending',
        });
        $op->update(['crew_checkin_at' => now()]);
        foreach (['cabina_lista', 'pasajeros_recibidos', 'in_flight', 'landed', 'postflight_pending'] as $status) {
            LineaTiempoOperacion::create(['operation_id' => $op->id, 'status' => $status, 'title' => 'Fixture evidence']);
        }

        // Create the requested historical/configuration order without changing production templates.
        $controller = app(SobrecargoControlador::class);
        $templates = (new \ReflectionMethod($controller, 'checklistTemplates'))->invoke($controller);
        $rows = collect($templates[$type])->keyBy(fn ($row) => $row[0]);
        $checklist = ChecklistOperacion::create([
            'operation_id' => $op->id, 'sobrecargo_user_id' => $op->sobrecargo_user_id,
            'type' => $type, 'status' => 'pending',
        ]);
        $expectedIds = [];
        foreach (array_unique(array_merge($codes, $rows->keys()->all())) as $code) {
            [$code, $itemCategory, $label, $critical] = $rows[$code];
            $completed = in_array($code, array_slice($codes, 1), true);
            $item = $checklist->items()->create([
                'code' => $code, 'category' => $itemCategory, 'label' => $label,
                'status' => $completed ? 'completed' : 'pending',
                'is_required' => true, 'is_critical' => $critical,
                'is_completed' => $completed,
                'completed_at' => $completed ? now()->subDay() : null,
            ]);
            if (in_array($code, $codes, true)) {
                $expectedIds[] = $item->id;
            }
        }
        $url = "/api/v1/sobrecargo/operations/{$op->id}/workflow";
        $get = fn () => $this->getJson($url)->assertOk()->json();
        $ids = fn ($payload) => collect($payload['checklists'])->mapWithKeys(
            fn ($group) => [$group['id'] => array_column($group['items'], 'id')]
        )->all();
        $categoryItems = fn ($payload) => collect($payload['checklists'])->firstWhere('id', $checklist->id)['items'];
        $before = $get();
        $beforeIds = $ids($before);
        $this->assertSame($codes, array_column(array_values(array_filter($categoryItems($before), fn ($i) => $i['category'] === $category)), 'code'));
        $this->assertSame($expectedIds, array_column(array_values(array_filter($categoryItems($before), fn ($i) => $i['category'] === $category)), 'id'));
        $this->assertSame($beforeIds, $ids($get()), 'Repeated GET before PUT');
        $itemBefore = ChecklistItem::findOrFail($expectedIds[0])->getAttributes();
        $this->travel(1)->minutes();
        $put = $this->putJson("/api/v1/sobrecargo/operations/{$op->id}/checklists/$type/items/{$expectedIds[0]}", [
            'status' => 'completed', 'notes' => 'Order regression test',
        ])->assertOk()->json();
        $this->assertSame($beforeIds[$checklist->id], array_column($put['checklist']['items'], 'id'));
        $after = $get();
        $this->assertSame($beforeIds, $ids($after), 'GET after PUT');
        $this->assertSame('completed', collect($categoryItems($after))->firstWhere('id', $expectedIds[0])['status']);
        $itemAfter = ChecklistItem::findOrFail($expectedIds[0])->getAttributes();
        $allowed = ['status', 'notes', 'is_completed', 'completed_at', 'completed_by', 'updated_at'];
        $this->assertSame(array_diff_key($itemBefore, array_flip($allowed)), array_diff_key($itemAfter, array_flip($allowed)));
        $this->assertSame($beforeIds, $ids($get()), 'Refresh GET');
        // New authentication and a new HTTP request, with no retained operation model.
        $crew = Usuario::findOrFail($op->sobrecargo_user_id);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $crew->email, 'password' => 'password'])->assertOk()->json('token');
        $this->withToken($token);
        $this->assertSame($beforeIds, $ids($get()), 'Reopen GET');
        $eager = Operacion::with('checklists.items')->findOrFail($op->id);
        $this->assertSame($beforeIds, $eager->checklists->mapWithKeys(fn ($cl) => [$cl->id => $cl->items->pluck('id')->all()])->all());
    }
}
