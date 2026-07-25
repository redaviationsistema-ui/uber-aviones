<?php

namespace Tests\Feature;

use App\Servicios\Ocr\GenericDocumentScannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class OcrDocumentoControladorTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_document_requires_a_front_image(): void
    {
        $response = $this->postJson('/api/v1/auth/ocr/scan-document', [
            'document_type' => 'passport',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('documentType', 'passport')
            ->assertJsonPath('warnings.0', 'missing_front_document');
    }

    public function test_scan_document_returns_structured_response_from_scanner_service(): void
    {
        $fakeTesseract = sys_get_temp_dir().'/fake_tesseract_ocr.sh';
        file_put_contents($fakeTesseract, "#!/bin/sh\nexit 0\n");
        chmod($fakeTesseract, 0755);

        config()->set('services.ocr.tesseract_path', $fakeTesseract);

        $mock = Mockery::mock(GenericDocumentScannerService::class);
        $mock->shouldReceive('scan')
            ->once()
            ->andReturn([
                'success' => true,
                'documentType' => 'passport',
                'documentSide' => 'front_back',
                'fields' => [
                    'name' => [
                        'value' => 'JUAN PEREZ',
                        'confidence' => 92,
                        'source' => 'ocr',
                    ],
                    'passport_number' => [
                        'value' => 'P1234567',
                        'confidence' => 97,
                        'source' => 'mrz',
                    ],
                ],
                'quality' => [
                    'blur' => 12.5,
                    'brightness' => 145.2,
                    'glare' => 3.1,
                    'documentDetected' => true,
                    'cropped' => true,
                ],
                'warnings' => ['manual_review_required'],
                'reviewFields' => ['expiration_date'],
                'processingTimeMs' => 1320,
            ]);

        $this->app->instance(GenericDocumentScannerService::class, $mock);

        $response = $this->post('/api/v1/auth/ocr/scan-document', [
            'document_front' => UploadedFile::fake()->image('front.jpg'),
            'document_back' => UploadedFile::fake()->image('back.jpg'),
            'document_type' => 'passport',
            'front_signals' => json_encode([['value' => 'P1234567', 'source' => 'mrz']]),
            'back_signals' => json_encode([]),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('documentType', 'passport')
            ->assertJsonPath('documentSide', 'front_back')
            ->assertJsonPath('fields.name.value', 'JUAN PEREZ')
            ->assertJsonPath('fields.passport_number.source', 'mrz')
            ->assertJsonPath('quality.documentDetected', true)
            ->assertJsonPath('warnings.0', 'manual_review_required')
            ->assertJsonPath('reviewFields.0', 'expiration_date')
            ->assertJsonPath('processingTimeMs', 1320);

        @unlink($fakeTesseract);
    }
}
