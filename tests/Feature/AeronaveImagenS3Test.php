<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\ImagenAeronave;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AeronaveImagenS3Test extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_upload_aircraft_image_to_s3_and_persist_public_url(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.disks.s3.url', 'https://red-aviation-images.s3.us-east-1.amazonaws.com');

        $this->seed();

        $user = Usuario::factory()->create([
            'role' => 'provider',
            'status' => 'active',
        ]);

        $user->provider()->create([
            'company_name' => 'Red Aviation Test',
            'commercial_name' => 'Red Aviation',
            'approval_status' => 'approved',
        ]);

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $user->provider->id,
        ]);

        $token = TokenApi::issue($user);

        $response = $this->withToken($token)
            ->post('/api/v1/proveedor/aeronaves/'.$aircraft->id.'/imagenes', [
                'image' => UploadedFile::fake()->image('avion.jpg'),
                'sort_order' => 1,
                'is_main' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('image.aircraft_id', $aircraft->id)
            ->assertJsonPath('image.sort_order', 1)
            ->assertJsonPath('image.is_main', true);

        $path = $response->json('path');
        $url = $response->json('url');

        Storage::disk('s3')->assertExists($path);

        $this->assertSame(
            'https://red-aviation-images.s3.us-east-1.amazonaws.com/'.$path,
            $url
        );

        $this->assertDatabaseHas('aircraft_images', [
            'aircraft_id' => $aircraft->id,
            'image_url' => $url,
            'sort_order' => 1,
            'is_main' => true,
        ]);
    }

    public function test_provider_can_delete_aircraft_image_and_remove_file_from_s3(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.disks.s3.url', 'https://red-aviation-images.s3.us-east-1.amazonaws.com');

        $this->seed();

        $user = Usuario::factory()->create([
            'role' => 'provider',
            'status' => 'active',
        ]);

        $user->provider()->create([
            'company_name' => 'Red Aviation Test',
            'commercial_name' => 'Red Aviation',
            'approval_status' => 'approved',
        ]);

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $user->provider->id,
        ]);

        $token = TokenApi::issue($user);

        $path = 'aircraft/demo.jpg';
        Storage::disk('s3')->put($path, 'fake-image-content');

        $image = ImagenAeronave::create([
            'aircraft_id' => $aircraft->id,
            'image_url' => 'https://red-aviation-images.s3.us-east-1.amazonaws.com/'.$path,
            'sort_order' => 0,
            'is_main' => false,
        ]);

        $this->withToken($token)
            ->deleteJson('/api/v1/proveedor/aeronaves/'.$aircraft->id.'/imagenes/'.$image->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('s3')->assertMissing($path);
        $this->assertDatabaseMissing('aircraft_images', ['id' => $image->id]);
    }
}
