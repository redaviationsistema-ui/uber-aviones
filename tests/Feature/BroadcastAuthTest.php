<?php

namespace Tests\Feature;

use App\Modelos\Proveedor;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_broadcast_auth_returns_pusher_signature_for_private_channel(): void
    {
        $this->seed();

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-pusher-key',
            'broadcasting.connections.pusher.secret' => 'test-pusher-secret',
            'broadcasting.connections.pusher.app_id' => 'test-pusher-app',
            'broadcasting.connections.pusher.options.cluster' => 'us2',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');

        $user = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
        ]);
        $user->syncRoles([Usuario::ROLE_PROVIDER], Usuario::ROLE_PROVIDER);

        $provider = Proveedor::query()->create([
            'user_id' => $user->id,
            'company_name' => 'Proveedor Realtime',
            'commercial_name' => 'Proveedor Realtime',
            'approval_status' => 'approved',
        ]);

        $user->forceFill(['provider_id' => $provider->id])->saveQuietly();

        $response = $this->withToken(TokenApi::issue($user))
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-provider.'.$provider->id,
            ], [
                'Accept' => 'application/json',
            ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->assertSame(
            'test-pusher-key:',
            substr((string) $response->json('auth'), 0, strlen('test-pusher-key:'))
        );
    }
}
