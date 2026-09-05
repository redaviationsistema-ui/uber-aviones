<?php

namespace Tests\Feature;

use App\Events\FlightConfirmed;
use App\Events\NewFlightRequestCreated;
use App\Modelos\Aeronave;
use App\Modelos\Notificacion;
use App\Modelos\Proveedor;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use App\Servicios\RedAviation\ProviderFlightNotificationService;
use App\Servicios\RedAviation\ProviderFlightRequestNotificationService;
use App\Servicios\ReintentoCoincidenciaSolicitudServicio;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProviderFlightNotificationsTest extends TestCase
{
    use DatabaseMigrations;

    // SQLite in-memory is discarded on disconnect. Do not execute unrelated legacy down()
    // migrations; they drop FK columns in an order unsupported by SQLite.
    public function runDatabaseMigrations()
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function () {
            DB::disconnect();
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        });
    }

    private function context(): array
    {
        $provider = Proveedor::factory()->create(['approval_status' => 'approved', 'admin_validation_status' => 'approved', 'access_enabled' => true]);
        $user = Usuario::findOrFail($provider->user_id);
        $user->update(['provider_id' => $provider->id, 'status' => 'active']);
        $aircraft = Aeronave::factory()->create(['provider_id' => $provider->id, 'capacity' => 8]);
        $flight = SolicitudVuelo::factory()->create(['assigned_provider_id' => $provider->id, 'assigned_aircraft_id' => $aircraft->id, 'payment_status' => 'pending']);
        $flight->matches()->create(['provider_id' => $provider->id, 'aircraft_id' => $aircraft->id, 'match_score' => 100, 'status' => 'pending']);
        return [$flight, $provider, $user];
    }

    private function confirm(SolicitudVuelo $flight): void
    {
        app(ProviderFlightNotificationService::class)->updateConfirmedPayment($flight, [
            'payment_status' => 'paid', 'workflow_status' => 'vuelo confirmado', 'status' => 'reserved',
        ]);
    }

    public function test_request_created_persists_one_shared_event_before_broadcast_and_retries_are_idempotent(): void
    {
        [$flight, $provider] = $this->context();
        Usuario::factory()->create(['provider_id' => $provider->id, 'role' => 'provider']);
        $seen = [];
        Event::listen(NewFlightRequestCreated::class, function ($event) use (&$seen) {
            $payload = $event->broadcastWith();
            $this->assertDatabaseHas('notifications', ['id' => $payload['notification_id'], 'idempotency_key' => $payload['event_key']]);
            $this->assertSame(0, DB::transactionLevel());
            $seen[] = $payload;
        });
        $service = app(ProviderFlightRequestNotificationService::class);
        DB::beginTransaction();
        $service->dispatchForFlightRequest($flight);
        $this->assertCount(0, $seen);
        $this->assertSame(1, Notificacion::where('type', 'flight.request.created')->count());
        DB::commit();
        $service->dispatchForFlightRequest($flight);
        $this->assertCount(1, $seen);
        $this->assertSame("provider:{$provider->id}:flight:{$flight->id}:request-created", $seen[0]['event_key']);
        $this->assertSame(1, Notificacion::where('type', 'flight.request.created')->count());
    }

    public function test_confirmed_transition_is_atomic_idempotent_and_targets_only_assigned_provider(): void
    {
        [$flight, $provider] = $this->context();
        $other = Aeronave::factory()->create();
        $flight->matches()->create(['provider_id' => $other->provider_id, 'aircraft_id' => $other->id, 'match_score' => 90, 'status' => 'rejected']);
        Event::fake([FlightConfirmed::class]);
        DB::beginTransaction();
        $this->confirm($flight);
        Event::assertNotDispatched(FlightConfirmed::class);
        DB::commit();
        $this->confirm($flight);
        $this->confirm($flight);
        $this->assertSame(1, Notificacion::where('type', 'flight.confirmed')->count());
        $key = "provider:{$provider->id}:flight:{$flight->id}:flight-confirmed";
        $this->assertDatabaseHas('notifications', ['idempotency_key' => $key, 'provider_id' => $provider->id, 'type' => 'flight.confirmed']);
        Event::assertDispatchedTimes(FlightConfirmed::class, 1);
        Event::assertDispatched(FlightConfirmed::class, fn ($event) => $event->providerId === $provider->id
            && $event->broadcastWith()['event_key'] === $key
            && $event->broadcastWith()['payment_status'] === 'paid');
    }

    public function test_rollback_leaves_no_notification_or_broadcast_and_retry_can_succeed(): void
    {
        [$flight] = $this->context();
        Event::fake([FlightConfirmed::class]);
        DB::beginTransaction();
        $this->confirm($flight);
        DB::rollBack();
        $this->assertSame(0, Notificacion::where('type', 'flight.confirmed')->count());
        Event::assertNotDispatched(FlightConfirmed::class);
        $this->confirm($flight);
        $this->assertSame(1, Notificacion::where('type', 'flight.confirmed')->count());
        Event::assertDispatchedTimes(FlightConfirmed::class, 1);
    }

    public function test_broadcast_failure_keeps_payment_and_notification_for_http_recovery(): void
    {
        [$flight] = $this->context();
        Event::listen(FlightConfirmed::class, fn () => throw new \RuntimeException('Simulated Pusher outage'));
        $this->confirm($flight);
        $this->assertSame('paid', $flight->fresh()->payment_status);
        $this->assertSame(1, Notificacion::where('type', 'flight.confirmed')->count());
    }

    public function test_unique_accepted_match_is_used_when_assignment_is_missing(): void
    {
        [$flight, $provider] = $this->context();
        $flight->update(['assigned_provider_id' => null]);
        $flight->matches()->update(['status' => 'accepted']);
        $this->confirm($flight);
        $this->assertDatabaseHas('notifications', ['provider_id' => $provider->id, 'type' => 'flight.confirmed']);
    }

    public function test_rejected_assignment_is_not_notified_when_another_provider_has_accepted(): void
    {
        [$flight, $rejected] = $this->context();
        $flight->matches()->update(['status' => 'rejected']);
        $acceptedAircraft = Aeronave::factory()->create();
        $flight->matches()->create(['provider_id' => $acceptedAircraft->provider_id,
            'aircraft_id' => $acceptedAircraft->id, 'match_score' => 100, 'status' => 'accepted']);
        $this->confirm($flight);
        $this->assertSame(0, Notificacion::where('provider_id', $rejected->id)->count());
        $this->assertDatabaseHas('notifications', ['provider_id' => $acceptedAircraft->provider_id, 'type' => 'flight.confirmed']);
    }

    public function test_already_paid_without_a_transition_does_not_emit(): void
    {
        [$flight] = $this->context();
        $flight->update(['payment_status' => 'paid', 'workflow_status' => 'vuelo confirmado']);
        $this->confirm($flight);
        $this->assertSame(0, Notificacion::where('type', 'flight.confirmed')->count());
    }

    public function test_shared_read_read_all_and_authorization_are_persistent(): void
    {
        [$flight, $provider, $owner] = $this->context();
        $this->confirm($flight);
        app(ProviderFlightRequestNotificationService::class)->dispatchForFlightRequest($flight);
        $colleague = Usuario::factory()->create(['role' => 'provider', 'provider_id' => $provider->id, 'status' => 'active']);
        $stranger = Usuario::factory()->create(['role' => 'provider', 'status' => 'active']);
        $notice = Notificacion::where('type', 'flight.confirmed')->firstOrFail();
        $this->withToken(TokenApi::issue($stranger, 'test'))->patchJson('/api/v1/notifications/'.$notice->id.'/read')->assertForbidden();
        $this->withToken(TokenApi::issue($colleague, 'test'))->patchJson('/api/v1/notifications/'.$notice->id.'/read')->assertOk();
        $this->assertNotNull($notice->fresh()->read_at);
        $this->withToken(TokenApi::issue($owner, 'test'))->getJson('/api/v1/proveedor/notificaciones')->assertOk()->assertJsonPath('unread_count', 1);
        $this->patchJson('/api/v1/notifications/read-all', ['types' => ProviderFlightNotificationService::TYPES])->assertOk()->assertJsonPath('unread_count', 0);
        $this->getJson('/api/v1/proveedor/notificaciones')->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_provider_without_assignment_or_valid_match_cannot_accept_or_reject(): void
    {
        [$flight] = $this->context();
        [, , $otherUser] = $this->context();
        $this->withToken(TokenApi::issue($otherUser, 'test'));
        $this->postJson('/api/v1/proveedor/solicitudes/'.$flight->id.'/aceptar')->assertForbidden();
        $this->postJson('/api/v1/proveedor/solicitudes/'.$flight->id.'/rechazar')->assertForbidden();
        $this->assertSame('pending', $flight->fresh()->status);
    }

    public function test_provider_with_pending_match_can_accept(): void
    {
        [$flight, $provider, $user] = $this->context();
        $flight->update(['assigned_provider_id' => null]);
        $this->withToken(TokenApi::issue($user, 'test'))->postJson('/api/v1/proveedor/solicitudes/'.$flight->id.'/aceptar')->assertOk();
        $this->assertSame($provider->id, $flight->fresh()->assigned_provider_id);
    }

    public function test_rematch_notifies_new_provider_but_not_previous_provider(): void
    {
        [$flight, $oldProvider] = $this->context();
        $newAircraft = Aeronave::factory()->create(['capacity' => 10, 'base_airport' => 'MMMX']);
        Proveedor::findOrFail($newAircraft->provider_id)->update(['approval_status' => 'approved', 'admin_validation_status' => 'approved']);
        $flight->matches()->update(['status' => 'rejected']);
        $flight->update(['assigned_provider_id' => null, 'assigned_aircraft_id' => null]);
        app(ReintentoCoincidenciaSolicitudServicio::class)->manejarRechazo($flight);
        $this->assertDatabaseHas('notifications', ['provider_id' => $newAircraft->provider_id, 'type' => 'flight.request.created']);
        $this->assertSame(0, Notificacion::where('provider_id', $oldProvider->id)->count());
    }
}
