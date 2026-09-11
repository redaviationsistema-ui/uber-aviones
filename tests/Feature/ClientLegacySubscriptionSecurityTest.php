<?php

namespace Tests\Feature;

use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientLegacySubscriptionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_cannot_activate_subscription_or_record_a_paid_payment(): void
    {
        $this->seed();
        $user = Usuario::factory()->create(['role' => 'client', 'status' => 'active']);
        $token = TokenApi::issue($user);
        $subscriptions = DB::table('subscriptions')->orderBy('id')->get()->toJson();
        $payments = DB::table('payments')->orderBy('id')->get()->toJson();

        $this->withToken($token)->postJson('/api/v1/cliente/suscripcion/contratar', [
            'plan_id' => DB::table('plans')->value('id'),
            'payment_provider' => 'stripe',
            'transaction_reference' => 'unverified-browser-reference',
            'status' => 'active',
            'payment_status' => 'paid',
        ])->assertStatus(410)->assertJsonPath('code', 'LEGACY_SUBSCRIPTION_ACTIVATION_DISABLED');

        $this->assertSame($subscriptions, DB::table('subscriptions')->orderBy('id')->get()->toJson());
        $this->assertSame($payments, DB::table('payments')->orderBy('id')->get()->toJson());
    }
}
