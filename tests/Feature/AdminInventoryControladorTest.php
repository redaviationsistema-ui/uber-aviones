<?php

namespace Tests\Feature;

use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class AdminInventoryControladorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createInventoryTables();
    }

    public function test_admin_can_upsert_query_update_and_delete_inventory_rows_via_laravel(): void
    {
        $admin = $this->createAdminUser();
        $token = TokenApi::issue($admin);

        $created = $this->withToken($token)->postJson('/api/v1/admin/inventory/upsert', [
            'table' => 'customers',
            'rows' => [
                [
                    'contact' => 'Laura Campos',
                    'email' => 'laura@test.dev',
                    'phone' => '5551234567',
                ],
            ],
            'unique_by' => ['email'],
            'single' => true,
        ]);

        $created
            ->assertOk()
            ->assertJsonPath('data.email', 'laura@test.dev');

        $this->withToken($token)->postJson('/api/v1/admin/inventory/query', [
            'table' => 'customers',
            'columns' => 'id,contact,email,phone',
            'filters' => [
                ['column' => 'email', 'operator' => 'eq', 'value' => 'laura@test.dev'],
            ],
            'single' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.contact', 'Laura Campos');

        $this->withToken($token)->patchJson('/api/v1/admin/inventory/update', [
            'table' => 'customers',
            'values' => [
                'phone' => '5559990000',
            ],
            'filters' => [
                ['column' => 'email', 'operator' => 'eq', 'value' => 'laura@test.dev'],
            ],
            'single' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', '5559990000');

        $this->assertDatabaseHas('customers', [
            'email' => 'laura@test.dev',
            'phone' => '5559990000',
        ]);

        $this->withToken($token)->deleteJson('/api/v1/admin/inventory/delete', [
            'table' => 'customers',
            'filters' => [
                ['column' => 'email', 'operator' => 'eq', 'value' => 'laura@test.dev'],
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('customers', [
            'email' => 'laura@test.dev',
        ]);
    }

    public function test_admin_can_upload_inventory_storage_assets_and_resolve_public_url(): void
    {
        Storage::fake('public');
        config()->set('filesystems.default', 'public');

        $admin = $this->createAdminUser();
        $token = TokenApi::issue($admin);

        $upload = $this->withToken($token)->post('/api/v1/admin/inventory/storage/upload', [
            'bucket' => 'bulk-email-images',
            'path' => 'campaigns/header.png',
            'file' => UploadedFile::fake()->image('header.png'),
        ]);

        $upload->assertCreated();
        $storedPath = $upload->json('data.path');

        Storage::disk('public')->assertExists($storedPath);

        $this->withToken($token)->getJson('/api/v1/admin/inventory/storage/url?bucket=bulk-email-images&path=campaigns/header.png')
            ->assertOk()
            ->assertJsonPath('data.signedUrl', Storage::disk('public')->url($storedPath));
    }

    public function test_admin_can_trigger_bulk_email_send_through_laravel(): void
    {
        Mail::fake();

        $admin = $this->createAdminUser();
        $token = TokenApi::issue($admin);

        $response = $this->withToken($token)->postJson('/api/v1/admin/inventory/bulk-email/send-test', [
            'email' => 'destinatario@test.dev',
            'subject' => 'Prueba campaña',
            'title' => 'Campaña activa',
            'content' => 'Contenido controlado por Laravel.',
            'button_text' => 'Abrir',
            'button_url' => 'https://example.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function createAdminUser(): Usuario
    {
        return Usuario::factory()->create([
            'role' => 'admin',
            'operational_role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createInventoryTables(): void
    {
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->string('contact')->nullable();
                $table->string('email')->nullable()->unique();
                $table->string('phone')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bulk_email_campaigns')) {
            Schema::create('bulk_email_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('subject')->nullable();
                $table->string('status')->nullable();
                $table->unsignedInteger('total_recipients')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }
}
