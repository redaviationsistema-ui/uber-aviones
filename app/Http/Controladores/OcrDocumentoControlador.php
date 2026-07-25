<?php

namespace App\Http\Controladores;

use App\Servicios\Ocr\DocumentScanConfig;
use App\Servicios\Ocr\GenericDocumentScannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OcrDocumentoControlador extends ControladorBase
{
    public function __construct(
        private readonly GenericDocumentScannerService $scanner = new GenericDocumentScannerService(),
    ) {
    }

    public function scanDocument(Request $request): JsonResponse
    {
        @set_time_limit(120);

        $data = $request->validate([
            'document_front' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
            'document_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
            'documento' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
            'document_type' => ['nullable', 'string', 'max:80'],
            'front_signals' => ['nullable'],
            'back_signals' => ['nullable'],
            'quality_front' => ['nullable'],
            'quality_back' => ['nullable'],
        ]);

        $frontFile = $request->file('document_front') ?: $request->file('documento');
        $backFile = $request->file('document_back');

        if (! $frontFile) {
            return response()->json([
                'success' => false,
                'documentType' => DocumentScanConfig::normalizeType($data['document_type'] ?? 'auto'),
                'documentSide' => 'front',
                'fields' => new \stdClass(),
                'quality' => [
                    'blur' => 0,
                    'brightness' => 0,
                    'glare' => 0,
                    'documentDetected' => false,
                    'cropped' => false,
                ],
                'warnings' => ['missing_front_document'],
                'reviewFields' => [],
                'processingTimeMs' => 0,
                'message' => 'Debes enviar al menos la imagen frontal del documento.',
            ], 422);
        }

        $tesseractPath = $this->resolveTesseractPath();
        if ($tesseractPath === null) {
            return response()->json([
                'success' => false,
                'documentType' => DocumentScanConfig::normalizeType($data['document_type'] ?? 'auto'),
                'documentSide' => $backFile ? 'front_back' : 'front',
                'fields' => new \stdClass(),
                'quality' => [
                    'blur' => 0,
                    'brightness' => 0,
                    'glare' => 0,
                    'documentDetected' => false,
                    'cropped' => false,
                ],
                'warnings' => ['backend_unavailable'],
                'reviewFields' => [],
                'processingTimeMs' => 0,
                'message' => 'El servidor no tiene Tesseract disponible. Configura OCR_TESSERACT_PATH o instala el binario.',
            ], 503);
        }

        try {
            $response = $this->scanner->scan([
                'front_path' => $frontFile->getRealPath(),
                'back_path' => $backFile?->getRealPath(),
                'document_type' => $data['document_type'] ?? 'auto',
                'front_signals' => $data['front_signals'] ?? [],
                'back_signals' => $data['back_signals'] ?? [],
                'quality_front' => $data['quality_front'] ?? [],
                'quality_back' => $data['quality_back'] ?? [],
            ], $tesseractPath);

            return response()->json($response);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'documentType' => DocumentScanConfig::normalizeType($data['document_type'] ?? 'auto'),
                'documentSide' => $backFile ? 'front_back' : 'front',
                'fields' => new \stdClass(),
                'quality' => [
                    'blur' => 0,
                    'brightness' => 0,
                    'glare' => 0,
                    'documentDetected' => false,
                    'cropped' => false,
                ],
                'warnings' => ['ocr_processing_failed'],
                'reviewFields' => [],
                'processingTimeMs' => 0,
                'message' => 'No fue posible procesar el documento.',
                'error' => app()->hasDebugModeEnabled() ? $exception->getMessage() : null,
            ], 500);
        }
    }

    private function resolveTesseractPath(): ?string
    {
        $configuredPath = trim((string) config('services.ocr.tesseract_path', env('OCR_TESSERACT_PATH', '')));

        $candidates = array_filter([
            $configuredPath,
            '/opt/homebrew/bin/tesseract',
            '/usr/local/bin/tesseract',
            '/usr/bin/tesseract',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $which = trim((string) shell_exec('command -v tesseract 2>/dev/null'));
        if ($which && is_file($which) && is_executable($which)) {
            return $which;
        }

        return null;
    }
}
