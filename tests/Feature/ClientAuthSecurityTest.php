<?php

namespace Tests\Feature;

use App\Modelos\RegistroAuditoria;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ClientAuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_notification_with_backend_url_and_audits(): void
    {
        Notification::fake();
        $this->seed();

        $user = Usuario::factory()->unverified()->create([
            'email' => 'cliente.auth@test.dev',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk();

        Notification::assertSentTo(
            $user,
            \App\Notifications\Auth\ResetPasswordNotification::class,
            function ($notification, array $channels) use ($user): bool {
                $mailMessage = $notification->toMail($user);
                $actionUrl = (string) ($mailMessage->actionUrl ?? '');

                $this->assertSame(['mail'], $channels);
                $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/api/v1/auth/password/reset/', $actionUrl);
                $this->assertStringContainsString('email='.urlencode($user->email), $actionUrl);

                return true;
            },
        );

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'password_reset_link_sent',
            'module' => 'auth',
        ]);
    }

    public function test_show_reset_password_accepts_valid_token(): void
    {
        $this->seed();

        $user = Usuario::factory()->create([
            'email' => 'cliente.reset-link@test.dev',
            'status' => 'active',
        ]);

        $token = Password::broker()->createToken($user);

        $this->getJson('/api/v1/auth/password/reset/'.$token.'?email='.urlencode($user->email))
            ->assertOk()
            ->assertJsonPath('token', $token)
            ->assertJsonPath('email', $user->email);
    }

    public function test_reset_password_updates_password_invalidates_tokens_and_audits(): void
    {
        $this->seed();

        $user = Usuario::factory()->create([
            'email' => 'cliente.reset@test.dev',
            'password' => Hash::make('PasswordViejo123'),
            'status' => 'active',
        ]);

        TokenApi::issue($user, 'old-session');
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NuevoPassword123',
            'password_confirmation' => 'NuevoPassword123',
        ]);

        $response->assertOk();

        $user->refresh();

        $this->assertTrue(Hash::check('NuevoPassword123', $user->password));
        $this->assertDatabaseCount('api_tokens', 0);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'password_reset_completed',
            'module' => 'auth',
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'OtroPassword123',
            'password_confirmation' => 'OtroPassword123',
        ])->assertStatus(422);
    }

    public function test_expired_reset_token_fails_validation_and_reset(): void
    {
        $this->seed();
        config()->set('auth.passwords.users.expire', 1);

        $user = Usuario::factory()->create([
            'email' => 'cliente.reset-expired@test.dev',
            'status' => 'active',
        ]);

        $token = Password::broker()->createToken($user);

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update([
                'created_at' => Carbon::now()->subMinutes(2),
            ]);

        $this->getJson('/api/v1/auth/password/reset/'.$token.'?email='.urlencode($user->email))
            ->assertStatus(403);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NuevoPassword123',
            'password_confirmation' => 'NuevoPassword123',
        ])->assertStatus(422);
    }

    public function test_send_email_verification_notification_generates_signed_link_and_audits(): void
    {
        Notification::fake();
        $this->seed();

        $user = Usuario::factory()->unverified()->create([
            'email' => 'cliente.verify-link@test.dev',
            'status' => 'active',
        ]);
        $token = TokenApi::issue($user, 'verify-mail');

        $this->withToken($token)
            ->postJson('/api/v1/auth/verify-email')
            ->assertOk();

        Notification::assertSentTo(
            $user,
            \App\Notifications\Auth\VerifyEmailNotification::class,
            function ($notification, array $channels) use ($user): bool {
                $mailMessage = $notification->toMail($user);
                $actionUrl = (string) ($mailMessage->actionUrl ?? '');

                $this->assertSame(['mail'], $channels);
                $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/api/v1/auth/email/verify/', $actionUrl);
                $this->assertStringContainsString('signature=', $actionUrl);
                $this->assertStringContainsString('expires=', $actionUrl);

                return true;
            },
        );

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'email_verification_link_sent',
            'module' => 'auth',
        ]);
    }

    public function test_email_verification_requires_signed_url_and_marks_user_as_verified(): void
    {
        $this->seed();

        $user = Usuario::factory()->unverified()->create([
            'email' => 'cliente.verify@test.dev',
            'status' => 'active',
        ]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $response = $this->getJson($url);

        $response
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'email_verified',
            'module' => 'auth',
        ]);
    }

    public function test_email_verification_rejects_invalid_hash_even_with_signed_url(): void
    {
        $this->seed();

        $user = Usuario::factory()->unverified()->create([
            'email' => 'cliente.verify-invalid@test.dev',
            'status' => 'active',
        ]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('otro-correo@test.dev'),
        ]);

        $this->getJson($url)->assertStatus(403);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_verification_rejects_expired_signed_url(): void
    {
        $this->seed();

        $user = Usuario::factory()->unverified()->create([
            'email' => 'cliente.verify-expired@test.dev',
            'status' => 'active',
        ]);

        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->getJson($url)->assertStatus(403);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_verification_returns_success_without_reauditing_verified_user(): void
    {
        $this->seed();

        $user = Usuario::factory()->create([
            'email' => 'cliente.verify-repeat@test.dev',
            'status' => 'active',
            'email_verified_at' => now()->subDay(),
        ]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->assertSame(
            0,
            RegistroAuditoria::query()
                ->where('user_id', $user->id)
                ->where('action', 'email_verified')
                ->count(),
        );
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $this->seed();

        Usuario::factory()->create([
            'email' => 'cliente.login@test.dev',
            'password' => Hash::make('PasswordCorrecto123'),
            'status' => 'active',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt += 1) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'cliente.login@test.dev',
                'password' => 'incorrecta',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'cliente.login@test.dev',
            'password' => 'incorrecta',
        ])->assertStatus(429);
    }

    public function test_health_route_blocks_production_when_mailer_is_log(): void
    {
        $this->app['env'] = 'production';
        config()->set('mail.default', 'log');
        config()->set('logging.default', 'errorlog');

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('checks.mail.ready', false)
            ->assertJsonPath('checks.mail.mailer', 'log');
    }

    public function test_health_route_stays_ready_in_production_with_smtp_mailer(): void
    {
        $this->app['env'] = 'production';
        config()->set('mail.default', 'smtp');

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.mail.ready', true)
            ->assertJsonPath('checks.mail.mailer', 'smtp');
    }
}
