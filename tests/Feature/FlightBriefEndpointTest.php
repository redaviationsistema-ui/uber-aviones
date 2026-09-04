<?php

namespace Tests\Feature;

use App\Dominio\Sobrecargo\CrewAssignmentStatus;
use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\ChecklistItem;
use App\Modelos\ChecklistOperacion;
use App\Modelos\ConfiguracionSistema;
use App\Modelos\Cotizacion;
use App\Modelos\Operacion;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\TramoSolicitudVuelo;
use App\Modelos\Usuario;
use App\Modelos\ImagenAeronave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightBriefEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_payment_returns_pending_readiness(): void
    {
        [$client, $token, $flightRequest] = $this->flightContext();

        $response = $this->brief($token, $flightRequest)
            ->assertOk()
            ->assertJsonPath('flight_brief.payment.confirmed', false)
            ->assertJsonPath('flight_brief.visible', false)
            ->assertJsonPath('flight_brief.readiness.code', 'payment_pending');

        $this->assertIsBool($response->json('flight_brief.visible'));
    }

    public function test_confirmed_payment_returns_paid_state_and_paid_at(): void
    {
        [, $token, $flightRequest, $reservation] = $this->flightContext();
        $this->confirmPayment($flightRequest, $reservation);

        $this->brief($token, $flightRequest)
            ->assertOk()
            ->assertJsonPath('flight_brief.payment.confirmed', true)
            ->assertJsonPath('flight_brief.visible', true)
            ->assertJsonPath('flight_brief.payment.status', 'paid')
            ->assertJsonPath('flight_brief.readiness.code', 'operation_pending');
    }

    public function test_confirmed_reservation_makes_the_brief_visible(): void
    {
        [, $token, $flightRequest, $reservation] = $this->flightContext();
        $reservation->update(['status' => 'confirmed', 'confirmed_at' => now()]);

        $this->brief($token, $flightRequest)
            ->assertOk()
            ->assertJsonPath('flight_brief.payment.confirmed', true)
            ->assertJsonPath('flight_brief.visible', true);
    }

    public function test_confirmed_payment_without_operation_returns_null_operation(): void
    {
        [, $token, $flightRequest, $reservation] = $this->flightContext();
        $this->confirmPayment($flightRequest, $reservation);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.operation.id', null)
            ->assertJsonPath('flight_brief.readiness.code', 'operation_pending');
    }

    public function test_operation_without_crew_is_not_ready(): void
    {
        [, $token, $flightRequest, $reservation] = $this->flightContext();
        $this->confirmPayment($flightRequest, $reservation);
        Operacion::query()->create(['flight_request_id' => $flightRequest->id, 'status' => 'confirmed']);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.crew.assigned', false)
            ->assertJsonPath('flight_brief.readiness.code', 'crew_unassigned');
    }

    public function test_pending_crew_assignment_is_not_ready(): void
    {
        [$client, $token, $flightRequest, $reservation] = $this->flightContext();
        $this->confirmPayment($flightRequest, $reservation);
        [$operation, $crew] = $this->operationWithCrew($flightRequest, CrewAssignmentStatus::PENDING_CONFIRMATION);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.crew.assigned', true)
            ->assertJsonPath('flight_brief.crew.confirmed', false)
            ->assertJsonPath('flight_brief.crew.status', CrewAssignmentStatus::PENDING_CONFIRMATION)
            ->assertJsonPath('flight_brief.readiness.code', 'crew_pending_confirmation');
    }

    public function test_confirmed_crew_without_checklist_is_not_ready(): void
    {
        [, $token, $flightRequest, $reservation] = $this->flightContext();
        $this->confirmPayment($flightRequest, $reservation);
        $this->operationWithCrew($flightRequest, CrewAssignmentStatus::CONFIRMED);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.crew.confirmed', true)
            ->assertJsonPath('flight_brief.checklist.exists', false)
            ->assertJsonPath('flight_brief.readiness.code', 'checklist_not_started');
    }

    public function test_partial_checklist_uses_completed_and_not_applicable_as_resolved(): void
    {
        [, $token, $flightRequest, $reservation] = $this->flightContext();
        $this->confirmPayment($flightRequest, $reservation);
        [$operation, $crew] = $this->operationWithCrew($flightRequest, CrewAssignmentStatus::CONFIRMED);
        $checklist = $this->checklist($operation, $crew);
        $this->checklistItem($checklist, 'completed', true);
        $this->checklistItem($checklist, 'not_applicable', true);
        $this->checklistItem($checklist, 'pending', true);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.checklist.completed', 2)
            ->assertJsonPath('flight_brief.checklist.total', 3)
            ->assertJsonPath('flight_brief.checklist.required_completed', 2)
            ->assertJsonPath('flight_brief.checklist.required_total', 3)
            ->assertJsonPath('flight_brief.checklist.percentage', 67)
            ->assertJsonPath('flight_brief.checklist.is_complete', false)
            ->assertJsonPath('flight_brief.readiness.code', 'checklist_in_progress');
    }

    public function test_complete_checklist_marks_flight_brief_ready(): void
    {
        [, $token, $flightRequest, $reservation] = $this->flightContext();
        $this->confirmPayment($flightRequest, $reservation);
        [$operation, $crew] = $this->operationWithCrew($flightRequest, CrewAssignmentStatus::CONFIRMED);
        $checklist = $this->checklist($operation, $crew, now());
        $this->checklistItem($checklist, 'completed', true);
        $this->checklistItem($checklist, 'not_applicable', true);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.checklist.is_complete', true)
            ->assertJsonPath('flight_brief.checklist.percentage', 100)
            ->assertJsonPath('flight_brief.readiness.ready', true)
            ->assertJsonPath('flight_brief.readiness.code', 'ready')
            ->assertJsonMissingPath('flight_brief.payment.stripe_payment_intent_id')
            ->assertJsonMissingPath('flight_brief.crew.email');
    }

    public function test_each_flight_request_returns_its_own_dynamic_route_aircraft_and_passengers(): void
    {
        [, $token, $flightRequestA] = $this->flightContext([
            'origin' => 'MMTO',
            'destination' => 'MMUN',
            'origin_name' => 'Aeropuerto Internacional de Toluca',
            'origin_city' => 'Toluca',
            'destination_name' => 'Aeropuerto Internacional de Cancun',
            'destination_city' => 'Cancun',
            'passengers' => 3,
            'aircraft_model' => 'Aircraft A',
            'registration' => 'XA-AAA',
            'image_url' => 'https://cdn.example.test/aircraft-a.jpg',
            'duration_hours' => 2.0833,
        ]);
        [, , $flightRequestB] = $this->flightContext([
            'origin' => 'MMGL',
            'destination' => 'MMAN',
            'origin_name' => 'Aeropuerto Internacional de Guadalajara',
            'origin_city' => 'Guadalajara',
            'destination_name' => 'Aeropuerto Internacional de Manzanillo',
            'destination_city' => 'Manzanillo',
            'passengers' => 8,
            'aircraft_model' => 'Aircraft B',
            'registration' => 'XA-BBB',
            'image_url' => 'https://cdn.example.test/aircraft-b.jpg',
            'duration_hours' => 1.5,
        ]);
        $flightRequestB->update(['client_id' => $flightRequestA->client_id]);

        $briefA = $this->brief($token, $flightRequestA)->assertOk()->json('flight_brief');
        $briefB = $this->brief($token, $flightRequestB)->assertOk()->json('flight_brief');

        $this->assertSame('MMTO', $briefA['departure']['code']);
        $this->assertSame('Toluca', $briefA['departure']['city']);
        $this->assertSame('Aircraft A', $briefA['aircraft']['model']);
        $this->assertSame('XA-AAA', $briefA['aircraft']['registration']);
        $this->assertSame('https://cdn.example.test/aircraft-a.jpg', $briefA['aircraft']['image_url']);
        $this->assertSame(3, $briefA['passengers']['count']);
        $this->assertSame(2.0833, $briefA['flight']['duration_hours']);
        $this->assertSame('MMGL', $briefB['departure']['code']);
        $this->assertSame('Manzanillo', $briefB['arrival']['city']);
        $this->assertSame('Aircraft B', $briefB['aircraft']['model']);
        $this->assertSame('XA-BBB', $briefB['aircraft']['registration']);
        $this->assertSame('https://cdn.example.test/aircraft-b.jpg', $briefB['aircraft']['image_url']);
        $this->assertSame(8, $briefB['passengers']['count']);
        $this->assertSame(1.5, $briefB['flight']['duration_hours']);
        $this->assertNotSame($briefA['departure']['code'], $briefB['departure']['code']);
    }

    public function test_a_fresh_brief_reflects_crew_and_checklist_updates_for_the_same_flight(): void
    {
        [, $token, $flightRequest, $reservation] = $this->flightContext();
        $this->confirmPayment($flightRequest, $reservation);
        [$operation, $crew] = $this->operationWithCrew($flightRequest, CrewAssignmentStatus::PENDING_CONFIRMATION);
        $checklist = $this->checklist($operation, $crew);

        foreach (range(1, 3) as $index) {
            $this->checklistItem($checklist, 'completed', true);
        }
        foreach (range(1, 7) as $index) {
            $this->checklistItem($checklist, 'pending', true);
        }

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.crew.confirmed', false)
            ->assertJsonPath('flight_brief.checklist.completed', 3)
            ->assertJsonPath('flight_brief.checklist.total', 10);

        $operation->update(['crew_status' => CrewAssignmentStatus::CONFIRMED, 'crew_confirmed_at' => now()]);
        $operation->latestCrewAssignment->update(['status' => CrewAssignmentStatus::CONFIRMED, 'accepted_at' => now()]);
        $checklist->items()->where('status', 'pending')->limit(4)->update(['status' => 'completed', 'is_completed' => true]);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.crew.confirmed', true)
            ->assertJsonPath('flight_brief.checklist.completed', 7)
            ->assertJsonPath('flight_brief.checklist.total', 10);
    }

    public function test_a_fresh_brief_reflects_an_aircraft_assignment_change(): void
    {
        [, $token, $flightRequest] = $this->flightContext(['aircraft_model' => 'Aircraft A', 'registration' => 'XA-AAA', 'image_url' => 'https://cdn.example.test/aircraft-a.jpg']);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.aircraft.model', 'Aircraft A')
            ->assertJsonPath('flight_brief.aircraft.registration', 'XA-AAA')
            ->assertJsonPath('flight_brief.aircraft.image_url', 'https://cdn.example.test/aircraft-a.jpg');

        $replacement = Aeronave::query()->create([
            'provider_id' => $flightRequest->assigned_provider_id, 'model' => 'Aircraft B', 'registration' => 'XA-BBB', 'category' => 'Light Jet', 'capacity' => 9,
            'base_airport' => 'MMGL', 'range_km' => 2400, 'speed_kmh' => 690, 'hourly_rate' => 5000,
            'climb_descent_minutes' => 30, 'status' => 'active', 'currency' => 'USD',
        ]);
        ImagenAeronave::query()->create(['aircraft_id' => $replacement->id, 'image_url' => 'https://cdn.example.test/aircraft-b.jpg', 'is_main' => true, 'visible_to_client' => true]);
        $flightRequest->update(['assigned_aircraft_id' => $replacement->id]);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.aircraft.model', 'Aircraft B')
            ->assertJsonPath('flight_brief.aircraft.registration', 'XA-BBB')
            ->assertJsonPath('flight_brief.aircraft.image_url', 'https://cdn.example.test/aircraft-b.jpg');
    }

    public function test_flight_brief_exposes_requested_services_without_confirming_them(): void
    {
        [, $token, $flightRequest] = $this->flightContext([
            'extras' => [
                'catering' => 'premium',
                'special_baggage' => 'Equipo deportivo',
                'ground_transport' => 'none',
            ],
        ]);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.services.catering.requested', true)
            ->assertJsonPath('flight_brief.services.special_baggage.requested', true)
            ->assertJsonPath('flight_brief.services.special_baggage.description', 'Equipo deportivo')
            ->assertJsonPath('flight_brief.services.ground_transport.requested', false)
            ->assertJsonMissingPath('flight_brief.services.catering.confirmed');
    }

    public function test_flight_brief_projects_complete_client_presentation_and_configured_support(): void
    {
        [, $token, $flightRequest] = $this->flightContext();
        $flightRequest->update([
            'visibility_payload' => [
                'presentation_location' => 'FBO Norte',
                'presentation_address' => 'Acceso norte 100, Toluca',
                'presentation_datetime' => '2026-09-12T09:30:00.000000Z',
                'presentation_instructions' => 'Sigue las indicaciones del acceso norte.',
                'presentation_maps_url' => 'https://maps.example.test/fbo-norte',
            ],
        ]);
        ConfiguracionSistema::query()->create([
            'key' => 'support_contact',
            'group' => 'support',
            'value' => [
                'name' => 'Operaciones Sky',
                'phone' => '+525500000000',
                'whatsapp' => 'https://wa.me/525500000000',
                'email' => 'ops@example.test',
            ],
        ]);

        $this->brief($token, $flightRequest)
            ->assertOk()
            ->assertJsonPath('flight_brief.presentation.airport_code', 'MMMX')
            ->assertJsonPath('flight_brief.presentation.airport_name', 'Aeropuerto Internacional de Ciudad de Mexico')
            ->assertJsonPath('flight_brief.presentation.city', 'Ciudad de Mexico')
            ->assertJsonPath('flight_brief.presentation.location_name', 'FBO Norte')
            ->assertJsonPath('flight_brief.presentation.address', 'Acceso norte 100, Toluca')
            ->assertJsonPath('flight_brief.presentation.presentation_datetime', '2026-09-12T09:30:00.000000Z')
            ->assertJsonPath('flight_brief.presentation.instructions', 'Sigue las indicaciones del acceso norte.')
            ->assertJsonPath('flight_brief.presentation.maps_url', 'https://maps.example.test/fbo-norte')
            ->assertJsonPath('flight_brief.presentation.is_complete', true)
            ->assertJsonPath('flight_brief.support.name', 'Operaciones Sky')
            ->assertJsonPath('flight_brief.support.phone', '+525500000000')
            ->assertJsonPath('flight_brief.support.whatsapp', 'https://wa.me/525500000000')
            ->assertJsonPath('flight_brief.support.email', 'ops@example.test');
    }

    public function test_flight_brief_returns_safe_nulls_for_incomplete_presentation_and_unconfigured_support(): void
    {
        [, $token, $flightRequest] = $this->flightContext();
        $flightRequest->update(['visibility_payload' => ['presentation_location' => 'FBO Norte']]);

        $this->brief($token, $flightRequest)
            ->assertOk()
            ->assertJsonPath('flight_brief.presentation.location_name', 'FBO Norte')
            ->assertJsonPath('flight_brief.presentation.address', null)
            ->assertJsonPath('flight_brief.presentation.presentation_datetime', null)
            ->assertJsonPath('flight_brief.presentation.instructions', null)
            ->assertJsonPath('flight_brief.presentation.maps_url', null)
            ->assertJsonPath('flight_brief.presentation.is_complete', false)
            ->assertJsonPath('flight_brief.support.name', null)
            ->assertJsonPath('flight_brief.support.phone', null)
            ->assertJsonPath('flight_brief.support.whatsapp', null)
            ->assertJsonPath('flight_brief.support.email', null)
            ->assertJsonMissingPath('flight_brief.provider.phone')
            ->assertJsonMissingPath('flight_brief.crew.phone');
    }

    public function test_cancelled_operation_and_flight_status_are_available_to_the_flight_brief(): void
    {
        [, $token, $flightRequest, $reservation] = $this->flightContext();
        $this->confirmPayment($flightRequest, $reservation);
        $flightRequest->update(['status' => 'cancelled']);
        Operacion::query()->create(['flight_request_id' => $flightRequest->id, 'status' => 'cancelada']);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.flight.status', 'cancelled')
            ->assertJsonPath('flight_brief.operation.status', 'cancelada');
    }

    public function test_flight_brief_reflects_the_operation_crew_flight_phase_after_assignment_confirmation(): void
    {
        [, $token, $flightRequest, $reservation] = $this->flightContext();
        $this->confirmPayment($flightRequest, $reservation);
        [$operation] = $this->operationWithCrew($flightRequest, CrewAssignmentStatus::CONFIRMED);
        $operation->update(['status' => 'en_vuelo', 'crew_status' => CrewAssignmentStatus::IN_FLIGHT]);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.operation.status', 'en_vuelo')
            ->assertJsonPath('flight_brief.crew.status', CrewAssignmentStatus::IN_FLIGHT);

        $operation->update(['crew_status' => CrewAssignmentStatus::LANDED]);

        $this->brief($token, $flightRequest)
            ->assertJsonPath('flight_brief.crew.status', CrewAssignmentStatus::LANDED);
    }

    public function test_client_cannot_read_another_clients_flight_brief(): void
    {
        [, , $flightRequest] = $this->flightContext();
        [$otherClient, $otherToken] = $this->clientToken('other-client@test.dev');

        $this->brief($otherToken, $flightRequest)->assertForbidden();
    }

    public function test_missing_flight_request_returns_not_found(): void
    {
        [, $token] = $this->clientToken('missing-client@test.dev');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/client/flight-requests/999999/flight-brief')
            ->assertNotFound();
    }

    private function flightContext(array $overrides = []): array
    {
        [$client, $token] = $this->clientToken();
        $providerUser = Usuario::factory()->create(['role' => Usuario::ROLE_PROVIDER]);
        $provider = Proveedor::query()->create(['user_id' => $providerUser->id, 'company_name' => 'Brief Provider', 'commercial_name' => 'Brief Provider']);
        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id, 'model' => $overrides['aircraft_model'] ?? 'Brief Jet', 'registration' => $overrides['registration'] ?? 'XA-BRIEF', 'category' => 'Light Jet', 'capacity' => 6,
            'base_airport' => 'MMMX', 'range_km' => 2400, 'speed_kmh' => 690, 'hourly_rate' => 5000,
            'climb_descent_minutes' => 30, 'status' => 'active', 'currency' => 'USD',
        ]);
        ImagenAeronave::query()->create([
            'aircraft_id' => $aircraft->id,
            'image_url' => $overrides['image_url'] ?? 'https://cdn.example.test/brief-jet.jpg',
            'is_main' => true,
            'visible_to_client' => true,
        ]);
        $origin = Aeropuerto::query()->create([
            'icao' => $overrides['origin'] ?? 'MMMX', 'name' => $overrides['origin_name'] ?? 'Aeropuerto Internacional de Ciudad de Mexico',
            'city' => $overrides['origin_city'] ?? 'Ciudad de Mexico', 'country' => 'MX', 'latitude' => 19.436, 'longitude' => -99.072, 'status' => 'active',
        ]);
        $destination = Aeropuerto::query()->create([
            'icao' => $overrides['destination'] ?? 'MMUN', 'name' => $overrides['destination_name'] ?? 'Aeropuerto Internacional de Cancun',
            'city' => $overrides['destination_city'] ?? 'Cancun', 'country' => 'MX', 'latitude' => 21.036, 'longitude' => -86.877, 'status' => 'active',
        ]);
        $departure = now()->addDays(3)->setTime(9, 30);
        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id, 'assigned_provider_id' => $provider->id, 'assigned_aircraft_id' => $aircraft->id,
            'origin' => $overrides['origin'] ?? 'MMMX', 'origin_airport_id' => $origin->id,
            'destination' => $overrides['destination'] ?? 'MMUN', 'destination_airport_id' => $destination->id,
            'departure_datetime' => $departure, 'passengers' => $overrides['passengers'] ?? 3,
            'pricing_context' => [
                'client_display_flight_hours' => $overrides['duration_hours'] ?? 1.25,
                'extras' => $overrides['extras'] ?? [],
            ],
            'trip_type' => 'one_way', 'status' => 'reserved', 'payment_status' => 'pending', 'currency' => 'USD',
        ]);
        TramoSolicitudVuelo::query()->create([
            'flight_request_id' => $flightRequest->id, 'leg_order' => 1, 'origin' => $flightRequest->origin,
            'origin_airport_id' => $origin->id, 'destination' => $flightRequest->destination, 'destination_airport_id' => $destination->id,
            'departure_datetime' => $departure, 'arrival_datetime' => $departure->copy()->addMinutes(75), 'passengers' => $flightRequest->passengers, 'status' => 'scheduled',
        ]);
        $quote = Cotizacion::query()->create([
            'flight_request_id' => $flightRequest->id, 'provider_id' => $provider->id, 'aircraft_id' => $aircraft->id,
            'subtotal' => 10000, 'taxes' => 0, 'fees' => 0, 'total' => 10000, 'currency' => 'USD', 'status' => 'accepted', 'expires_at' => now()->addDay(),
        ]);
        $reservation = Reserva::query()->create([
            'client_id' => $client->id, 'provider_id' => $provider->id, 'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id, 'quote_id' => $quote->id, 'reservation_code' => 'BRIEF-'.$flightRequest->id,
            'status' => 'pending_payment', 'total_amount' => 10000, 'currency' => 'USD',
        ]);

        return [$client, $token, $flightRequest, $reservation];
    }

    private function confirmPayment(SolicitudVuelo $flightRequest, Reserva $reservation): void
    {
        $flightRequest->update(['payment_status' => 'paid']);
        $reservation->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        Pago::query()->create([
            'user_id' => $flightRequest->client_id, 'reservation_id' => $reservation->id, 'flight_request_id' => $flightRequest->id,
            'payment_type' => 'reservation', 'amount' => 10000, 'currency' => 'USD', 'provider' => 'stripe',
            'transaction_reference' => 'brief-payment-'.$flightRequest->id, 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    private function operationWithCrew(SolicitudVuelo $flightRequest, string $assignmentStatus): array
    {
        $crew = Usuario::factory()->create(['name' => 'Sobrecargo Brief', 'role' => Usuario::ROLE_CLIENT, 'operational_role' => Usuario::ROLE_SOBRECARGO]);
        $operation = Operacion::query()->create([
            'flight_request_id' => $flightRequest->id, 'sobrecargo_user_id' => $crew->id, 'status' => 'confirmed', 'crew_status' => $assignmentStatus,
            'crew_confirmed_at' => $assignmentStatus === CrewAssignmentStatus::CONFIRMED ? now() : null,
        ]);
        AsignacionSobrecargo::query()->create([
            'operation_id' => $operation->id, 'sobrecargo_user_id' => $crew->id, 'role' => 'sobrecargo', 'status' => $assignmentStatus,
            'assigned_at' => now(), 'accepted_at' => $assignmentStatus === CrewAssignmentStatus::CONFIRMED ? now() : null,
        ]);

        return [$operation, $crew];
    }

    private function checklist(Operacion $operation, Usuario $crew, mixed $submittedAt = null): ChecklistOperacion
    {
        return ChecklistOperacion::query()->create([
            'operation_id' => $operation->id, 'sobrecargo_user_id' => $crew->id, 'type' => 'preflight', 'status' => 'pending', 'submitted_at' => $submittedAt,
        ]);
    }

    private function checklistItem(ChecklistOperacion $checklist, string $status, bool $required): void
    {
        ChecklistItem::query()->create([
            'checklist_id' => $checklist->id, 'code' => 'item_'.ChecklistItem::query()->count(), 'category' => 'test', 'label' => 'Item de prueba',
            'status' => $status, 'is_required' => $required, 'is_critical' => false,
            'is_completed' => in_array($status, ['completed', 'not_applicable'], true),
        ]);
    }

    private function clientToken(?string $email = null): array
    {
        $client = Usuario::factory()->create(['role' => Usuario::ROLE_CLIENT, 'email' => $email ?: 'brief-client-'.uniqid().'@test.dev']);

        return [$client, TokenApi::issue($client, 'flight-brief-test')];
    }

    private function brief(string $token, SolicitudVuelo $flightRequest)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/client/flight-requests/'.$flightRequest->id.'/flight-brief');
    }
}
