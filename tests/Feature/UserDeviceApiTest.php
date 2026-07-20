<?php

namespace Tests\Feature;

use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDeviceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_update_and_revoke_device(): void
    {
        $user = Usuario::factory()->create(['status' => 'active']);
        $token = TokenApi::issue($user, 'device-test');

        $this->withToken($token)->postJson('/api/v1/auth/devices', [
            'device_uuid' => 'device-a',
            'push_token' => 'token-a',
            'platform' => 'android',
            'app_version' => '1.0.0',
        ])->assertCreated()->assertJsonPath('device.device_uuid', 'device-a');

        $this->withToken($token)->putJson('/api/v1/auth/devices/device-a', [
            'push_token' => 'token-b',
            'platform' => 'android',
        ])->assertOk();

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_uuid' => 'device-a',
            'push_token' => 'token-b',
        ]);

        $this->withToken($token)->deleteJson('/api/v1/auth/devices/device-a')->assertOk();
        $this->assertDatabaseMissing('user_devices', ['device_uuid' => 'device-a']);
    }

    public function test_registering_same_physical_device_moves_it_to_current_account(): void
    {
        $first = Usuario::factory()->create(['status' => 'active']);
        $second = Usuario::factory()->create(['status' => 'active']);

        $this->withToken(TokenApi::issue($first))->postJson('/api/v1/auth/devices', [
            'device_uuid' => 'shared-device',
            'push_token' => 'first-token',
            'platform' => 'ios',
        ])->assertCreated();

        $this->withToken(TokenApi::issue($second))->postJson('/api/v1/auth/devices', [
            'device_uuid' => 'shared-device',
            'push_token' => 'second-token',
            'platform' => 'ios',
        ])->assertOk();

        $this->assertDatabaseCount('user_devices', 1);
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $second->id,
            'device_uuid' => 'shared-device',
            'push_token' => 'second-token',
        ]);
    }

    public function test_user_cannot_update_another_users_device(): void
    {
        $owner = Usuario::factory()->create(['status' => 'active']);
        $attacker = Usuario::factory()->create(['status' => 'active']);
        $this->withToken(TokenApi::issue($owner))->postJson('/api/v1/auth/devices', [
            'device_uuid' => 'owned-device',
            'push_token' => 'owner-token',
            'platform' => 'android',
        ])->assertCreated();

        $this->withToken(TokenApi::issue($attacker))->putJson('/api/v1/auth/devices/owned-device', [
            'push_token' => 'stolen-token',
        ])->assertNotFound();

        $this->assertDatabaseHas('user_devices', ['push_token' => 'owner-token']);
    }
}
