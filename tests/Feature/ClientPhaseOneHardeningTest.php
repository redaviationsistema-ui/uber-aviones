<?php
namespace Tests\Feature;
use App\Modelos\{Aeronave, Proveedor, Reserva, SolicitudVuelo, TokenApi, Usuario, ContratoReserva, Operacion};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Notification, Password};
use Tests\TestCase;
class ClientPhaseOneHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_provider_serialization_is_public_and_admin_provider_is_unchanged(): void
    {
        $c = $this->createContext();
        $c['provider']->update(['rfc' => 'PRIVATE-RFC', 'company_email' => 'private@example.test', 'admin_notes' => 'PRIVATE-NOTES']);
        DB::table('users')->where('id', $c['provider']->user_id)->update(['temporary_password_visible' => 'HISTORICAL-SECRET']);
        $c['aircraft']->update(['dispatch_notes' => 'PRIVATE-DISPATCH', 'security_notes' => 'PRIVATE-SECURITY']);
        foreach (['/api/v1/cliente/reservas', '/api/v1/cliente/reservas/'.$c['reservation']->id] as $path) {
            $r = $this->withToken($c['token'])->getJson($path)->assertOk();
            foreach (['PRIVATE-RFC','private@example.test','PRIVATE-NOTES','HISTORICAL-SECRET','PRIVATE-DISPATCH','PRIVATE-SECURITY','temporary_password_visible'] as $secret) {
                $this->assertStringNotContainsString($secret, $r->getContent());
            }
        }
        $r = $this->withToken($c['token'])->getJson('/api/v1/cliente/reservas/'.$c['reservation']->id)->assertOk();
        $this->assertSame(['id','company_name','commercial_name'], array_keys($r->json('reservation.provider')));
        $this->assertSame('PRIVATE-RFC', $c['provider']->fresh()->rfc);
        $this->assertSame('HISTORICAL-SECRET', DB::table('users')->where('id', $c['provider']->user_id)->value('temporary_password_visible'));
    }

    public function test_admin_creation_and_reset_use_hash_and_existing_one_time_broker(): void
    {
        Notification::fake();
        $admin = Usuario::factory()->create(['role' => 'admin', 'status' => 'active']);
        $r = $this->withToken(TokenApi::issue($admin))->postJson('/api/v1/admin/users', [
            'name' => 'New Provider', 'email' => 'new-provider@example.test', 'role' => 'provider',
        ])->assertCreated();
        $user = Usuario::where('email', 'new-provider@example.test')->firstOrFail();
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('temporary_password_visible'));
        $this->assertNotSame('', $user->password);
        $this->assertNotSame('unknown', password_get_info($user->password)['algoName']);
        $this->assertStringNotContainsString('temporary_password', $r->getContent());
        Notification::assertSentTo($user, \App\Notifications\Auth\ResetPasswordNotification::class);
        $before = $user->password;
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/admin/users/'.$user->id.'/reset-password')->assertOk();
        $this->assertSame($before, $user->fresh()->password);
        $token = Password::broker()->createToken($user);
        $body=['email'=>$user->email,'token'=>$token,'password'=>'NewPassword123!','password_confirmation'=>'NewPassword123!'];
        $this->postJson('/api/v1/auth/reset-password', $body)->assertOk();
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->postJson('/api/v1/auth/reset-password', $body)->assertStatus(422);
    }

    public function test_visible_password_cannot_be_written_via_model(): void
    {
        $user = Usuario::factory()->create();
        $this->expectException(\LogicException::class);
        $user->forceFill(['temporary_password_visible'=>'not-persistable'])->save();
    }

    public function test_cookie_is_not_authentication_and_bearer_still_works(): void
    {
        $user = Usuario::factory()->create(['role'=>'client','status'=>'active']);
        $token=TokenApi::issue($user);
        $this->withUnencryptedCookie('red_aviation_session', $token)
            ->putJson('/api/v1/cliente/perfil', ['name'=>'CSRF attempt'])->assertUnauthorized();
        $this->assertNotSame('CSRF attempt', $user->fresh()->name);
        $this->withToken($token)->putJson('/api/v1/cliente/perfil', ['name'=>'Bearer update'])->assertOk();
        $this->assertSame('Bearer update', $user->fresh()->name);
    }

    public function test_cors_allows_explicit_origin_and_idempotency_header_only(): void
    {
        config(['cors.allowed_origins'=>['https://allowed.example']]);
        $this->withHeaders(['Origin'=>'https://allowed.example','Access-Control-Request-Method'=>'POST','Access-Control-Request-Headers'=>'authorization,idempotency-key'])
            ->options('/api/v1/cliente/reservas')->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', 'https://allowed.example')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
        $r=$this->options('/api/v1/cliente/reservas');
        $this->assertStringContainsString('idempotency-key', strtolower($r->headers->get('Access-Control-Allow-Headers')));
        $this->withHeader('Origin','https://evil.example')->options('/api/v1/cliente/reservas')->assertStatus(204)->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withHeader('Origin','https://evil.example')->postJson('/api/v1/cliente/reservas', [])->assertForbidden();
    }

    public function test_legacy_request_routes_are_gone_without_creating_requests(): void
    {
        $c=$this->createContext();
        $this->withToken($c['token'])->getJson('/api/v1/cliente/solicitudes')->assertGone();
        $this->postJson('/api/v1/cliente/solicitudes',[])->assertGone();
        $this->getJson('/api/v1/cliente/solicitudes/'.$c['flightRequest']->id)->assertGone();
        $this->assertDatabaseCount('flight_requests',1);
        $this->getJson('/api/v1/client/flight-requests/'.$c['flightRequest']->id)->assertOk();
    }

    public function test_acceptance_is_exclusive_and_keeps_quotation_step_separate(): void
    {
        $c=$this->createContext();
        $flight=$c['flightRequest'];
        $flight->matches()->create(['provider_id'=>$c['provider']->id,'aircraft_id'=>$c['aircraft']->id,'status'=>'pending']);
        $service=app(\App\Servicios\Reservas\ProviderAcceptanceService::class);
        $this->assertNull($service->accept($flight->id,$c['provider']->id,$c['provider']->user_id,false));
        $this->assertNull($service->accept($flight->id,$c['provider']->id,$c['provider']->user_id,false));
        $this->assertDatabaseCount('operations',0);
        $one=$service->accept($flight->id,$c['provider']->id,$c['provider']->user_id,true);
        $two=$service->accept($flight->id,$c['provider']->id,$c['provider']->user_id,true);
        $this->assertSame($one->id,$two->id);
        $this->assertDatabaseCount('operations',1);
        $this->assertDatabaseCount('operation_timeline',1);
        $other=Proveedor::factory()->create();
        $flight->matches()->create(['provider_id'=>$other->id,'aircraft_id'=>$c['aircraft']->id,'status'=>'pending']);
        try { $service->accept($flight->id,$other->id,$other->user_id,true); $this->fail('Must reject reassignment'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409,$e->getStatusCode()); }
        $this->assertSame($c['provider']->id,$flight->fresh()->assigned_provider_id);
        $this->assertDatabaseCount('operations',1);
    }

    public function test_rejected_match_never_creates_operation(): void
    {
        $c=$this->createContext();
        $c['flightRequest']->matches()->create(['provider_id'=>$c['provider']->id,'aircraft_id'=>$c['aircraft']->id,'status'=>'rejected']);
        try { app(\App\Servicios\Reservas\ProviderAcceptanceService::class)->accept($c['flightRequest']->id,$c['provider']->id,$c['provider']->user_id,true); $this->fail('Must reject'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409,$e->getStatusCode()); }
        $this->assertDatabaseCount('operations',0);
    }

    public function test_database_preserves_reservation_and_contract_when_parents_are_deleted(): void
    {
        $c=$this->createContext();
        ContratoReserva::create(['reservation_id'=>$c['reservation']->id,'contract_code'=>'HISTORY','status'=>'generated']);
        foreach ([$c['user'],$c['provider'],$c['aircraft'],$c['flightRequest'],$c['reservation']] as $parent) {
            try { DB::transaction(fn ()=>$parent->delete()); $this->fail('Delete must be restricted'); }
            catch (\Illuminate\Database\QueryException $e) { $this->assertNotEmpty($e->getMessage()); }
            $this->assertDatabaseCount('reservations',1);
            $this->assertDatabaseCount('reservation_contracts',1);
        }
    }

    private function createContext(): array
    {
        $client = Usuario::factory()->create(['role' => Usuario::ROLE_CLIENT, 'status' => 'active']);
        $providerUser = Usuario::factory()->create(['role' => Usuario::ROLE_PROVIDER, 'status' => 'active']);
        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Canonical Routing Provider',
            'commercial_name' => 'Canonical Routing Provider',
            'approval_status' => 'approved',
        ]);
        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation Routing',
            'registration' => 'XA-'.strtoupper(substr(uniqid(), -7)),
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 5000,
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => now()->addDays(5),
            'passengers' => 2,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'final_price' => 15000,
            'currency' => 'USD',
            'status' => 'reserved',
        ]);
        $reservation = Reserva::query()->forceCreate([
            'id' => 900001,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'reservation_code' => 'PV-ROUTING-'.$flightRequest->id,
            'status' => 'pending_payment',
            'total_amount' => 15000,
            'currency' => 'USD',
        ]);

        $token = TokenApi::issue($client, 'test-canonical-routing');

        return [
            'user' => $client,
            'token' => $token,
            'reservation' => $reservation,
            'provider' => $provider,
            'aircraft' => $aircraft,
            'flightRequest' => $flightRequest,
        ];
    }
}
