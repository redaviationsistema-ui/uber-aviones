<?php

namespace Tests\Feature;

use App\Servicios\Ocr\DocumentScanConfig;
use App\Servicios\Ocr\GenericDocumentScannerService;
use ReflectionClass;
use Tests\TestCase;

class GenericDocumentScannerServiceTest extends TestCase
{
    public function test_it_extracts_a_name_from_ine_machine_readable_text(): void
    {
        $service = new GenericDocumentScannerService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('extractIneMachineReadableName');
        $method->setAccessible(true);

        $rawText = 'EX2373820720<<5 D1<<38049<9 MH 0111023H32123 GARCIA<MERCADO<<GERMAN';
        $name = $method->invoke($service, $rawText);

        $this->assertSame('GERMAN GARCIA MERCADO', $name);
    }

    public function test_it_extracts_birth_date_from_ine_machine_block(): void
    {
        $service = new GenericDocumentScannerService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('extractIneMachineBirthDate');
        $method->setAccessible(true);

        $birthDate = $method->invoke($service, '0111023H32123');

        $this->assertSame('2001-11-02', $birthDate);
    }

    public function test_it_marks_missing_expected_fields_for_manual_review(): void
    {
        $service = new GenericDocumentScannerService();
        $reflection = new ReflectionClass($service);
        $normalizeMethod = $reflection->getMethod('ensureExpectedFields');
        $normalizeMethod->setAccessible(true);
        $reviewMethod = $reflection->getMethod('resolveReviewFields');
        $reviewMethod->setAccessible(true);

        $fields = $normalizeMethod->invoke($service, DocumentScanConfig::TYPE_INE, [
            'document_type' => ['value' => 'ine', 'confidence' => 98, 'source' => 'ocr'],
            'document_number' => ['value' => 'EX2373820720', 'confidence' => 92, 'source' => 'ocr'],
        ]);

        $reviewFields = $reviewMethod->invoke($service, DocumentScanConfig::TYPE_INE, $fields, []);

        $this->assertContains('name', $reviewFields);
        $this->assertContains('curp', $reviewFields);
        $this->assertContains('birth_date', $reviewFields);
        $this->assertContains('expiration_date', $reviewFields);
    }

    public function test_it_marks_suspicious_short_names_for_manual_review(): void
    {
        $service = new GenericDocumentScannerService();
        $reflection = new ReflectionClass($service);
        $reviewMethod = $reflection->getMethod('resolveReviewFields');
        $reviewMethod->setAccessible(true);

        $reviewFields = $reviewMethod->invoke($service, DocumentScanConfig::TYPE_INE, [
            'name' => ['value' => 'GERMAN ADO', 'confidence' => 92, 'source' => 'barcode'],
            'document_number' => ['value' => 'EX2373820720', 'confidence' => 92, 'source' => 'ocr'],
        ], []);

        $this->assertContains('name', $reviewFields);
    }
}
