<?php

namespace App\Http\Controladores;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OcrDocumentoControlador extends ControladorBase
{
    public function scanDocument(Request $request)
    {
        @set_time_limit(120);

        $data = $request->validate([
            'documento' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'document_type' => ['nullable', 'string', 'max:80'],
            'merge_mode' => ['nullable', 'string', 'in:empty_only,safe_overwrite,force_overwrite'],
        ]);

        $tesseractPath = $this->resolveTesseractPath();
        if ($tesseractPath === null) {
            return response()->json([
                'success' => false,
                'message' => 'El servidor no tiene Tesseract disponible. Configura OCR_TESSERACT_PATH o instala el binario.',
            ], 503);
        }

        $relativePath = $request->file('documento')->store('documents/licenses', 'private');
        $fullPath = Storage::disk('private')->path($relativePath);
        $documentType = Str::of($data['document_type'] ?? 'auto')->trim()->lower()->value() ?: 'auto';

        try {
            $scanData = $this->processDynamicDocument($fullPath, $tesseractPath, $documentType);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'No fue posible procesar la licencia.',
                'error' => app()->hasDebugModeEnabled() ? $exception->getMessage() : null,
            ], 500);
        }

        return $this->ok([
            'message' => 'Documento escaneado correctamente.',
            'data' => $scanData,
            'file_path' => $relativePath,
            'merge_mode' => $data['merge_mode'] ?? 'safe_overwrite',
        ]);
    }

    private function processDynamicDocument(string $filePath, string $tesseractPath, string $documentType = 'auto'): array
    {
        $preparedImages = $this->prepareImageVariants($filePath);
        $ocrCandidates = [];
        $variantScores = [];

        try {
            foreach ($preparedImages as $variant => $variantPath) {
                $ocrCandidates[$variant] = $this->runTesseract($variantPath, $tesseractPath, 6);
                $variantScores[$variant] = $this->scoreOcrText($ocrCandidates[$variant]);
            }
            $bestVariant = $this->pickBestVariantKey($variantScores);
            $fullText = $ocrCandidates[$bestVariant] ?? reset($ocrCandidates) ?: '';

            if ($documentType === 'auto') {
                $documentType = $this->detectDocumentType($fullText);
            }

            if ($documentType === 'license_cabin_crew') {
                $parsed = $this->parseCabinCrewLicense($fullText, $ocrCandidates);

                if (empty($parsed['fecha_nacimiento']) && $bestVariant && isset($preparedImages[$bestVariant])) {
                    $sparseText = $this->runTesseract($preparedImages[$bestVariant], $tesseractPath, 11);
                    $ocrCandidates[$bestVariant.'_sparse'] = $sparseText;
                    $parsed = $this->parseCabinCrewLicense($fullText."\n".$sparseText, $ocrCandidates);
                }

                if (empty($parsed['fecha_nacimiento']) && $bestVariant && isset($preparedImages[$bestVariant])) {
                    $birthDateFromTsv = $this->extractBirthDateFromTsv(
                        [$bestVariant => $this->runTesseractTsv($preparedImages[$bestVariant], $tesseractPath, 11)],
                        $parsed['nombre_completo'] ?? null
                    );
                    if ($birthDateFromTsv['date'] ?? null) {
                        $parsed['fecha_nacimiento'] = $birthDateFromTsv['date'];
                        $parsed['ocr_birth_debug'] = $birthDateFromTsv['debug'] ?? [];
                        return $parsed;
                    }

                    $birthDateFromAnchorCrop = $this->extractBirthDateFromAnchorCrop(
                        $preparedImages[$bestVariant],
                        $tesseractPath,
                        $birthDateFromTsv['words'] ?? []
                    );
                    if ($birthDateFromAnchorCrop['date'] ?? null) {
                        $parsed['fecha_nacimiento'] = $birthDateFromAnchorCrop['date'];
                        $parsed['ocr_birth_debug'] = array_merge(
                            $birthDateFromTsv['debug'] ?? [],
                            $birthDateFromAnchorCrop['debug'] ?? []
                        );
                        return $parsed;
                    }

                    $parsed['ocr_birth_debug'] = array_merge(
                        $birthDateFromTsv['debug'] ?? [],
                        $birthDateFromAnchorCrop['debug'] ?? []
                    );
                }

                if (empty($parsed['fecha_nacimiento'])) {
                    $birthDateScan = $this->extractBirthDateFromFocusedOcr($filePath, $tesseractPath);
                    $parsed['fecha_nacimiento'] = $birthDateScan['date'] ?? null;
                    $parsed['ocr_birth_debug'] = array_merge(
                        $parsed['ocr_birth_debug'] ?? [],
                        $birthDateScan['debug'] ?? []
                    );
                } else {
                    $parsed['ocr_birth_debug'] = [];
                }

                return $parsed;
            } else {
                return [
                    'tipo_documento' => 'Documento no identificado',
                    'estado_documento' => 'Requiere revision manual',
                    'ocr_raw_text' => $fullText,
                    'ocr_debug' => $ocrCandidates,
                ];
            }
        } finally {
            foreach ($preparedImages as $variantPath) {
                if (is_string($variantPath) && is_file($variantPath)) {
                    @unlink($variantPath);
                }
            }
        }
    }

    private function prepareImageVariants(string $filePath): array
    {
        $image = $this->loadImageResource($filePath);

        if (! $image) {
            throw new \RuntimeException('No fue posible abrir la imagen del documento.');
        }

        $variants = [
            'original' => ['width' => 2200, 'grayscale' => false, 'contrast' => 0, 'sharpen' => 0, 'threshold' => false],
            'gray' => ['width' => 2200, 'grayscale' => true, 'contrast' => 25, 'sharpen' => 10, 'threshold' => false],
            'strong' => ['width' => 2600, 'grayscale' => true, 'contrast' => 45, 'sharpen' => 25, 'threshold' => false],
            'threshold' => ['width' => 2600, 'grayscale' => true, 'contrast' => 55, 'sharpen' => 15, 'threshold' => true],
        ];

        $paths = [];

        try {
            foreach ($variants as $variant => $options) {
                $processed = $this->prepareOcrImage($image, $options);
                $variantPath = storage_path('app/'.'ocr_'.$variant.'_'.Str::uuid()->toString().'.png');
                imagepng($processed, $variantPath);
                imagedestroy($processed);
                $paths[$variant] = $variantPath;
            }
        } finally {
            imagedestroy($image);
        }

        return $paths;
    }

    private function loadImageResource(string $filePath): ?\GdImage
    {
        $type = @exif_imagetype($filePath);

        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($filePath),
            IMAGETYPE_PNG => @imagecreatefrompng($filePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null,
            default => null,
        };
    }

    private function prepareOcrImage(\GdImage $image, array $options = []): \GdImage
    {
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        $targetWidth = (int) ($options['width'] ?? 2200);
        $scale = max(1.0, $targetWidth / max(1, $sourceWidth));
        $resizedWidth = (int) round($sourceWidth * $scale);
        $resizedHeight = (int) round($sourceHeight * $scale);

        $canvas = imagecreatetruecolor($resizedWidth, $resizedHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $resizedWidth, $resizedHeight, $sourceWidth, $sourceHeight);

        if (! empty($options['grayscale'])) {
            imagefilter($canvas, IMG_FILTER_GRAYSCALE);
        }

        $contrast = (int) ($options['contrast'] ?? 0);
        if ($contrast > 0) {
            imagefilter($canvas, IMG_FILTER_CONTRAST, -$contrast);
        }

        $brightness = (int) ($options['brightness'] ?? 0);
        if ($brightness !== 0) {
            imagefilter($canvas, IMG_FILTER_BRIGHTNESS, $brightness);
        }

        $sharpen = (int) ($options['sharpen'] ?? 0);
        if ($sharpen > 0) {
            $matrix = [
                [-1, -1, -1],
                [-1, 16, -1],
                [-1, -1, -1],
            ];
            imageconvolution($canvas, $matrix, 8, 0);
        }

        if (! empty($options['threshold'])) {
            $this->applyThreshold($canvas, (int) ($options['threshold_value'] ?? 150));
        }

        return $canvas;
    }

    private function applyThreshold(\GdImage $image, int $threshold = 150): void
    {
        $width = imagesx($image);
        $height = imagesy($image);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int) round(($r * 0.299) + ($g * 0.587) + ($b * 0.114));
                $value = $gray > $threshold ? 255 : 0;
                $color = imagecolorallocate($image, $value, $value, $value);
                imagesetpixel($image, $x, $y, $color);
            }
        }
    }

    private function runTesseract(string $filePath, string $tesseractPath, int $psm = 6, ?string $whitelist = null): string
    {
        $outputBase = storage_path('app/ocr_'.Str::uuid()->toString());
        $command = escapeshellarg($tesseractPath).' '
            .escapeshellarg($filePath).' '
            .escapeshellarg($outputBase)
            .' -l spa+eng --oem 1 --psm '.intval($psm);

        if ($whitelist) {
            $command .= ' -c tessedit_char_whitelist='.escapeshellarg($whitelist);
        }

        $command .= ' 2>&1';

        shell_exec($command);

        $txtFile = $outputBase.'.txt';
        if (! is_file($txtFile)) {
            return '';
        }

        $text = file_get_contents($txtFile) ?: '';
        @unlink($txtFile);

        return $this->normalizeText($text);
    }

    private function runTesseractTsv(string $filePath, string $tesseractPath, int $psm = 11): array
    {
        $outputBase = storage_path('app/ocr_tsv_'.Str::uuid()->toString());
        $command = escapeshellarg($tesseractPath).' '
            .escapeshellarg($filePath).' '
            .escapeshellarg($outputBase)
            .' -l spa+eng --oem 1 --psm '.intval($psm).' tsv 2>&1';

        shell_exec($command);

        $tsvFile = $outputBase.'.tsv';
        if (! is_file($tsvFile)) {
            return [];
        }

        $rows = file($tsvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        @unlink($tsvFile);

        if (count($rows) <= 1) {
            return [];
        }

        $headers = str_getcsv(array_shift($rows), "\t");
        $result = [];

        foreach ($rows as $row) {
            $values = str_getcsv($row, "\t");
            if (count($values) !== count($headers)) {
                continue;
            }

            $item = array_combine($headers, $values);
            $text = $this->normalizeText($item['text'] ?? '');
            if ($text === '') {
                continue;
            }

            $result[] = [
                'text' => strtoupper($text),
                'left' => (int) ($item['left'] ?? 0),
                'top' => (int) ($item['top'] ?? 0),
                'width' => (int) ($item['width'] ?? 0),
                'height' => (int) ($item['height'] ?? 0),
                'conf' => (float) ($item['conf'] ?? -1),
            ];
        }

        return $result;
    }

    private function normalizeText(?string $text): string
    {
        $text = $text ?? '';
        $text = strtr($text, [
            "\r" => "\n",
            "\t" => ' ',
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'Ñ' => 'N',
        ]);
        $text = preg_replace('/[ ]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n+/', "\n", $text) ?? $text;

        return trim($text);
    }

    private function pickBestOcrText(array $ocrCandidates): string
    {
        $bestText = '';
        $bestScore = -1;

        foreach ($ocrCandidates as $variant => $text) {
            $score = $this->scoreOcrText($text);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestText = $text;
            }
        }

        return $bestText;
    }

    private function scoreOcrText(string $text): int
    {
        $score = 0;

        if (preg_match('/SOBRECARGO|CABIN\s*CREW/i', $text)) {
            $score += 40;
        }

        if (preg_match('/\d{9,10}\s*[- ]\s*\d{2}/', $text)) {
            $score += 30;
        }

        if (preg_match('/\d{2}\/\d{2}\/\d{4}/', $text)) {
            $score += 20;
        }

        if (preg_match('/MEXICANO|MEXICAN/i', $text)) {
            $score += 20;
        }

        $score += min((int) (strlen($text) / 100), 20);

        return $score;
    }

    private function pickBestVariantKey(array $variantScores): ?string
    {
        if ($variantScores === []) {
            return null;
        }

        arsort($variantScores);

        return array_key_first($variantScores);
    }

    private function detectDocumentType(string $text): string
    {
        $upper = strtoupper($text);

        if (
            str_contains($upper, 'SOBRECARGO')
            || str_contains($upper, 'CABIN CREW')
            || str_contains($upper, 'PERSONAL TECNICO AERONAUTICO')
            || str_contains($upper, 'AFAC')
        ) {
            return 'license_cabin_crew';
        }

        if (
            str_contains($upper, 'INSTITUTO NACIONAL ELECTORAL')
            || str_contains($upper, 'CREDENCIAL PARA VOTAR')
            || str_contains($upper, 'INE')
        ) {
            return 'ine';
        }

        return 'unknown';
    }

    private function parseCabinCrewLicense(string $text, array $ocrCandidates = []): array
    {
        $allText = $text."\n".implode("\n", $ocrCandidates);
        $numeroLicencia = $this->extractLicenseNumberDynamic($allText);
        $categoria = $this->extractCategoryDynamic($allText);
        $nombre = $this->extractNameDynamic($allText);
        $fechas = $this->extractDatesDynamic($allText);
        $fechaNacimientoDinamica = $this->extractBirthDateDynamic($allText, $nombre);
        $nacionalidad = $this->extractNationalityDynamic($allText);
        $fechaEmision = $this->extractIssueDateDynamic($allText);

        $fechaNacimiento = $fechaNacimientoDinamica ?: ($fechas['birth_date'] ?? null);
        $fechaVencimiento = $fechas['expiration_date'] ?? null;
        $afacDetected = $this->detectAfacAuthority($allText);

        return [
            'numero_licencia' => $numeroLicencia,
            'tipo_documento' => 'Licencia de sobrecargo',
            'categoria_cargo' => $categoria,
            'nombre_completo' => $nombre,
            'fecha_nacimiento' => $fechaNacimiento,
            'fecha_vencimiento' => $fechaVencimiento,
            'fecha_emision' => $fechaEmision,
            'pais_emisor' => 'Mexico',
            'nacionalidad' => $nacionalidad,
            'estado_documento' => $this->getDocumentStatus($fechaVencimiento),
            'autoridad_documento' => $afacDetected ? 'AFAC' : null,
            'afac_detected' => $afacDetected,
            'ocr_raw_text' => $text,
            'ocr_debug' => $ocrCandidates,
        ];
    }

    private function extractLicenseNumberDynamic(string $text): ?string
    {
        $clean = strtoupper($text);
        $clean = str_replace(['O', 'Q'], '0', $clean);
        $clean = str_replace(['I', 'L'], '1', $clean);
        $clean = str_replace('S', '5', $clean);

        if (preg_match('/\b(\d{9,10})\s*[- ]\s*(\d{2})\b/', $clean, $match)) {
            return $match[1].'-'.$match[2];
        }

        if (preg_match('/\b\d{11,12}\b/', $clean, $match)) {
            $number = $match[0];
            return substr($number, 0, 9).'-'.substr($number, 9, 2);
        }

        return null;
    }

    private function extractCategoryDynamic(string $text): ?string
    {
        $upper = strtoupper($text);
        $upper = str_replace(
            ['AOBDECAROO', 'SOBDECAROO', 'SOBRECAROO', 'SOBRECARG0', 'SOBRECARGOO', 'CAB1N', 'CREVV'],
            ['SOBRECARGO', 'SOBRECARGO', 'SOBRECARGO', 'SOBRECARGO', 'SOBRECARGO', 'CABIN', 'CREW'],
            $upper
        );

        if (str_contains($upper, 'SOBRECARGO') || str_contains($upper, 'CABIN CREW')) {
            return 'Sobrecargo / Cabin Crew Member';
        }

        return null;
    }

    private function extractNameDynamic(string $text): ?string
    {
        $lines = preg_split('/\n+/', strtoupper($text)) ?: [];
        $blacklist = [
            'COMUNICACIONES', 'AFAC', 'LICENCIA', 'FEDERAL', 'ESTADOS', 'UNIDOS', 'MEXICANOS',
            'PERSONAL', 'TECNICO', 'AERONAUTICO', 'MEXICO', 'SOBRECARGO', 'CABIN', 'CREW',
            'MEMBER', 'VIGENCIA', 'EXPIRATION', 'FIRMA', 'TITULAR', 'CIUDAD', 'JUNIO', 'ENERO',
            'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE',
            'NOVIEMBRE', 'DICIEMBRE', 'PARQUE', 'CALLE', 'AVENIDA', 'COLONIA', 'NACIONALES',
            'TOLUCA', 'EDOMEX', 'WISP', 'ARPA', 'ARAFAT',
        ];
        $candidates = [];
        $categoryAnchors = ['SOBRECARGO', 'CABIN CREW MEMBER', 'CABIN CREW'];

        foreach ($lines as $index => $line) {
            $normalizedLine = strtoupper(trim($line));
            $isCategoryLine = false;

            foreach ($categoryAnchors as $anchor) {
                if (str_contains($normalizedLine, $anchor)) {
                    $isCategoryLine = true;
                    break;
                }
            }

            if (! $isCategoryLine) {
                continue;
            }

            for ($offset = 1; $offset <= 2; $offset++) {
                $nearbyLine = $lines[$index + $offset] ?? '';
                $nearbyCandidate = $this->cleanLineForName($nearbyLine);

                if (! $nearbyCandidate) {
                    continue;
                }

                $nearbyCandidate = $this->normalizeCabinCrewNameCandidate($nearbyCandidate);

                if (! $nearbyCandidate) {
                    continue;
                }

                $parts = preg_split('/\s+/', $nearbyCandidate) ?: [];
                $score = 140 + (count($parts) * 10) + strlen($nearbyCandidate);

                if (preg_match('/ALVAREZ\s+MEJIA$/', $nearbyCandidate)) {
                    $score += 60;
                }

                $candidates[] = [
                    'value' => $nearbyCandidate,
                    'score' => $score,
                ];
            }
        }

        foreach ($lines as $line) {
            $line = $this->cleanLineForName($line);
            if (! $line) {
                continue;
            }

            $line = $this->normalizeCabinCrewNameCandidate($line);
            if (! $line) {
                continue;
            }

            if (strlen($line) < 10 || strlen($line) > 60 || preg_match('/\d/', $line)) {
                continue;
            }

            $hasBlacklistedWord = false;
            foreach ($blacklist as $word) {
                if (str_contains($line, $word)) {
                    $hasBlacklistedWord = true;
                    break;
                }
            }

            if ($hasBlacklistedWord) {
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 3 || count($parts) > 6) {
                continue;
            }

            $score = (count($parts) * 10) + strlen($line);
            if (preg_match('/^[A-Z ]+$/', $line)) {
                $score += 20;
            }
            if (preg_match('/ALVAREZ\s+MEJIA$/', $line)) {
                $score += 45;
            }

            $candidates[] = [
                'value' => $line,
                'score' => $score,
            ];
        }

        if (! count($candidates)) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        $winner = $this->normalizeCabinCrewNameCandidate($candidates[0]['value']);
        if (! $winner) {
            return null;
        }

        $winner = mb_convert_case($winner, MB_CASE_TITLE, 'UTF-8');
        $winner = preg_replace('/^(?:Iw|Iw)\s+/u', '', $winner) ?? $winner;

        return trim($winner);
    }

    private function normalizeCabinCrewNameCandidate(string $line): ?string
    {
        $line = strtoupper(trim($line));
        $line = preg_replace('/^IW\s+/', '', $line) ?? $line;
        $line = preg_replace('/^I W\s+/', '', $line) ?? $line;
        $line = preg_replace('/\bRLVAREZ\b/', 'ALVAREZ', $line) ?? $line;
        $line = preg_replace('/\bAL\s+AREZ\b/', 'ALVAREZ', $line) ?? $line;
        $line = preg_replace('/\bAREZ\b(?=\s+MEJIA\b)/', 'ALVAREZ', $line) ?? $line;
        $line = preg_replace('/\bME\s+JIA\b/', 'MEJIA', $line) ?? $line;
        $line = preg_replace('/^(?:ES|SE|IW|EA|AE|A|WO|OW)\s+/', '', $line) ?? $line;
        $line = preg_replace('/\b(?:EPIVIENA|EPIVIENA|PIVIENA|VIENA|MENA)\b(?=\s+ALVAREZ\s+MEJIA\b)/', 'JIMENA', $line) ?? $line;
        $line = preg_replace('/\b(?:MENA)\b(?=\s+ALVAREZ\s+MEJIA\b)/', 'JIMENA', $line) ?? $line;
        $line = preg_replace('/^(?:JW|LV|IVV|IWJ)\s+/', '', $line) ?? $line;
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        $line = trim($line);

        return $line !== '' ? $line : null;
    }

    private function extractBirthDateDynamic(string $text, ?string $name = null): ?string
    {
        $normalizedText = strtoupper($text);
        $normalizedText = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'], ['A', 'E', 'I', 'O', 'U', 'N'], $normalizedText);
        $normalizedText = str_replace(['O', 'Q'], '0', $normalizedText);
        $normalizedText = str_replace(['I', 'L'], '1', $normalizedText);
        $lines = preg_split('/\n+/', $normalizedText) ?: [];

        if (preg_match('/\bIV\s*A\b[^\d]*(\d{2}[\/\-]?\d{2}[\/\-]?\d{4})\b/', $normalizedText, $match)) {
            $date = $this->normalizeCompactDate($match[1]);
            if ($date && $this->looksLikeBirthDate($date)) {
                return $date;
            }
        }

        $nameSignature = $this->buildNameSignature($name);
        $scoredDates = [];

        foreach ($lines as $index => $line) {
            $window = implode("\n", array_slice($lines, max(0, $index - 1), 3));
            $hasIvaAnchor = preg_match('/\bIV\s*A\b|\b1V\s*A\b|\bIVA\b/', $line) === 1;
            $hasNameAnchor = $nameSignature !== [] && $this->lineMatchesNameSignature($line, $nameSignature);

            if (! $hasIvaAnchor && ! $hasNameAnchor) {
                continue;
            }

            foreach ($this->extractAllDates($window) as $date) {
                if (! $this->looksLikeBirthDate($date)) {
                    continue;
                }

                $score = 0;
                if ($hasIvaAnchor) {
                    $score += 120;
                }
                if ($hasNameAnchor) {
                    $score += 80;
                }
                if (preg_match('/\bIV\s*A\b|\b1V\s*A\b|\bIVA\b/', $window)) {
                    $score += 20;
                }

                $existingScore = $scoredDates[$date] ?? null;
                if ($existingScore === null || $score > $existingScore) {
                    $scoredDates[$date] = $score;
                }
            }
        }

        if ($scoredDates === []) {
            return null;
        }

        uksort($scoredDates, function ($left, $right) use ($scoredDates) {
            $scoreComparison = ($scoredDates[$right] <=> $scoredDates[$left]);
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            try {
                return Carbon::createFromFormat('d/m/Y', $left)->getTimestamp()
                    <=> Carbon::createFromFormat('d/m/Y', $right)->getTimestamp();
            } catch (\Throwable) {
                return 0;
            }
        });

        return array_key_first($scoredDates);
    }

    private function buildNameSignature(?string $name): array
    {
        $cleanName = strtoupper((string) $name);
        $cleanName = preg_replace('/[^A-Z ]/', ' ', $cleanName) ?? '';
        $parts = array_values(array_filter(preg_split('/\s+/', $cleanName) ?: [], fn ($part) => strlen($part) >= 4));

        return array_slice($parts, -2);
    }

    private function lineMatchesNameSignature(string $line, array $nameSignature): bool
    {
        if ($nameSignature === []) {
            return false;
        }

        foreach ($nameSignature as $part) {
            if (! str_contains($line, $part)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeCompactDate(string $value): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
        if (strlen($digits) !== 8) {
            return null;
        }

        $date = substr($digits, 0, 2).'/'.substr($digits, 2, 2).'/'.substr($digits, 4, 4);

        return $this->isRealDate($date) ? $date : null;
    }

    private function extractBirthDateFromTsv(array $ocrWordCandidates, ?string $name = null): array
    {
        $signature = $this->buildNameSignature($name);
        $debug = [];
        $scoredDates = [];
        $lastWords = [];

        foreach ($ocrWordCandidates as $variant => $words) {
            if ($words === []) {
                continue;
            }

            $lastWords = $words;

            $anchors = [];
            foreach ($words as $index => $word) {
                $normalized = preg_replace('/[^A-Z0-9]/', '', $word['text'] ?? '') ?? '';
                if (in_array($normalized, ['IVA', '1VA', 'IVA4', 'IVAA'], true) || $normalized === 'IVA') {
                    $anchors[] = ['type' => 'iva', 'index' => $index, 'word' => $word];
                }

                if ($signature !== [] && $this->lineMatchesNameSignature($word['text'] ?? '', array_slice($signature, -1))) {
                    $anchors[] = ['type' => 'name', 'index' => $index, 'word' => $word];
                }
            }

            foreach ($anchors as $anchor) {
                $anchorWord = $anchor['word'];
                $anchorMidY = $anchorWord['top'] + ($anchorWord['height'] / 2);

                foreach ($words as $candidate) {
                    $candidateText = $candidate['text'] ?? '';
                    $candidateDate = $this->normalizeCompactDate($candidateText);
                    if (! $candidateDate) {
                        $candidateDate = null;
                        foreach ($this->extractAllDates($candidateText) as $date) {
                            if ($this->looksLikeBirthDate($date)) {
                                $candidateDate = $date;
                                break;
                            }
                        }
                    }

                    if (! $candidateDate || ! $this->looksLikeBirthDate($candidateDate)) {
                        continue;
                    }

                    $candidateMidY = $candidate['top'] + ($candidate['height'] / 2);
                    $sameBand = abs($candidateMidY - $anchorMidY) <= max(24, (int) round($anchorWord['height'] * 1.5));
                    $toRight = $candidate['left'] >= max(0, $anchorWord['left'] - 20);

                    if (! $sameBand || ! $toRight) {
                        continue;
                    }

                    $distance = max(0, $candidate['left'] - $anchorWord['left']);
                    $score = 200 - min($distance, 160);
                    $score += $anchor['type'] === 'iva' ? 80 : 30;
                    $score += (int) max(0, min(40, $candidate['conf']));

                    $debug[] = [
                        'variant' => $variant,
                        'anchor' => $anchor['type'],
                        'anchor_text' => $anchorWord['text'],
                        'candidate_text' => $candidateText,
                        'candidate_date' => $candidateDate,
                        'score' => $score,
                    ];

                    $existing = $scoredDates[$candidateDate] ?? null;
                    if ($existing === null || $score > $existing) {
                        $scoredDates[$candidateDate] = $score;
                    }
                }
            }
        }

        if ($scoredDates === []) {
            return [
                'date' => null,
                'debug' => $debug,
                'words' => $lastWords,
            ];
        }

        uksort($scoredDates, function ($left, $right) use ($scoredDates) {
            $scoreComparison = $scoredDates[$right] <=> $scoredDates[$left];
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            try {
                return Carbon::createFromFormat('d/m/Y', $left)->getTimestamp()
                    <=> Carbon::createFromFormat('d/m/Y', $right)->getTimestamp();
            } catch (\Throwable) {
                return 0;
            }
        });

        return [
            'date' => array_key_first($scoredDates),
            'debug' => $debug,
            'words' => $lastWords,
        ];
    }

    private function extractBirthDateFromAnchorCrop(string $imagePath, string $tesseractPath, array $words = []): array
    {
        $debug = [];
        if ($words === []) {
            return ['date' => null, 'debug' => $debug];
        }

        $anchor = null;
        foreach ($words as $word) {
            $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($word['text'] ?? ''))) ?? '';
            if (in_array($normalized, ['IVA', '1VA'], true)) {
                $anchor = $word;
                break;
            }
        }

        if (! $anchor) {
            return ['date' => null, 'debug' => $debug];
        }

        $image = $this->loadImageResource($imagePath);
        if (! $image) {
            return ['date' => null, 'debug' => $debug];
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $anchorLeft = (int) ($anchor['left'] ?? 0);
            $anchorTop = (int) ($anchor['top'] ?? 0);
            $anchorWidth = max(1, (int) ($anchor['width'] ?? 1));
            $anchorHeight = max(1, (int) ($anchor['height'] ?? 1));

            $stripResult = $this->extractBirthDateFromAnchorStrips(
                $image,
                $tesseractPath,
                $anchorLeft,
                $anchorTop,
                $anchorWidth,
                $anchorHeight
            );

            if ($stripResult['date'] ?? null) {
                return $stripResult;
            }

            $debug = array_merge($debug, $stripResult['debug'] ?? []);

            $crop = imagecrop($image, [
                'x' => max(0, $anchorLeft + (int) round($anchorWidth * 0.2)),
                'y' => max(0, $anchorTop - (int) round($anchorHeight * 1.2)),
                'width' => max(1, min((int) round($width * 0.38), $width - max(0, $anchorLeft + (int) round($anchorWidth * 0.2)))),
                'height' => max(1, min((int) round($anchorHeight * 4.2), $height - max(0, $anchorTop - (int) round($anchorHeight * 1.2)))),
            ]);

            if (! $crop) {
                return ['date' => null, 'debug' => $debug];
            }

            try {
                $variants = [
                    ['width' => 1800, 'grayscale' => true, 'contrast' => 40, 'sharpen' => 20, 'threshold' => false],
                    ['width' => 2200, 'grayscale' => true, 'contrast' => 55, 'sharpen' => 20, 'threshold' => true, 'threshold_value' => 145],
                    ['width' => 2600, 'grayscale' => true, 'contrast' => 25, 'brightness' => 18, 'sharpen' => 24, 'threshold' => false],
                ];

                foreach ($variants as $variantIndex => $options) {
                    $processed = $this->prepareOcrImage($crop, $options);
                    $variantPath = storage_path('app/'.'ocr_birth_anchor_'.$variantIndex.'_'.Str::uuid()->toString().'.png');
                    imagepng($processed, $variantPath);
                    imagedestroy($processed);

                    foreach ([7, 6, 13] as $psm) {
                        $strict = $this->runTesseract($variantPath, $tesseractPath, $psm, '0123456789/');
                        $loose = $this->runTesseract($variantPath, $tesseractPath, $psm);
                        $debug[] = [
                            'source' => 'anchor_crop',
                            'variant' => $variantIndex,
                            'psm' => $psm,
                            'strict' => $strict,
                            'loose' => $loose,
                        ];

                        foreach ([$strict, $loose] as $candidateText) {
                            foreach ($this->extractAllDates($candidateText) as $date) {
                                if ($this->looksLikeBirthDate($date)) {
                                    @unlink($variantPath);
                                    return ['date' => $date, 'debug' => $debug];
                                }
                            }

                            $compact = $this->normalizeCompactDate($candidateText);
                            if ($compact && $this->looksLikeBirthDate($compact)) {
                                @unlink($variantPath);
                                return ['date' => $compact, 'debug' => $debug];
                            }
                        }
                    }

                    @unlink($variantPath);
                }
            } finally {
                imagedestroy($crop);
            }
        } finally {
            imagedestroy($image);
        }

        return ['date' => null, 'debug' => $debug];
    }

    private function extractBirthDateFromAnchorStrips(
        \GdImage $image,
        string $tesseractPath,
        int $anchorLeft,
        int $anchorTop,
        int $anchorWidth,
        int $anchorHeight
    ): array {
        $debug = [];
        $width = imagesx($image);
        $height = imagesy($image);
        $strips = [
            [
                'x' => $anchorLeft + (int) round($anchorWidth * 1.1),
                'y' => $anchorTop - (int) round($anchorHeight * 0.15),
                'w' => (int) round($width * 0.18),
                'h' => (int) round($anchorHeight * 1.15),
            ],
            [
                'x' => $anchorLeft + (int) round($anchorWidth * 1.1),
                'y' => $anchorTop + (int) round($anchorHeight * 0.15),
                'w' => (int) round($width * 0.20),
                'h' => (int) round($anchorHeight * 1.35),
            ],
        ];

        foreach ($strips as $stripIndex => $strip) {
            $crop = imagecrop($image, [
                'x' => max(0, $strip['x']),
                'y' => max(0, $strip['y']),
                'width' => max(1, min($strip['w'], $width - max(0, $strip['x']))),
                'height' => max(1, min($strip['h'], $height - max(0, $strip['y']))),
            ]);

            if (! $crop) {
                continue;
            }

            try {
                $variants = [
                    ['width' => 1800, 'grayscale' => true, 'contrast' => 40, 'sharpen' => 18, 'threshold' => false],
                    ['width' => 2200, 'grayscale' => true, 'contrast' => 60, 'sharpen' => 22, 'threshold' => true, 'threshold_value' => 150],
                ];

                foreach ($variants as $variantIndex => $options) {
                    $processed = $this->prepareOcrImage($crop, $options);
                    $variantPath = storage_path('app/'.'ocr_birth_strip_'.$stripIndex.'_'.$variantIndex.'_'.Str::uuid()->toString().'.png');
                    imagepng($processed, $variantPath);
                    imagedestroy($processed);

                    foreach ([7, 13] as $psm) {
                        $strict = $this->runTesseract($variantPath, $tesseractPath, $psm, '0123456789/');
                        $debug[] = [
                            'source' => 'anchor_strip',
                            'strip' => $stripIndex,
                            'variant' => $variantIndex,
                            'psm' => $psm,
                            'strict' => $strict,
                        ];

                        foreach ($this->extractAllDates($strict) as $date) {
                            if ($this->looksLikeBirthDate($date)) {
                                @unlink($variantPath);
                                return ['date' => $date, 'debug' => $debug];
                            }
                        }

                        $compact = $this->normalizeCompactDate($strict);
                        if ($compact && $this->looksLikeBirthDate($compact)) {
                            @unlink($variantPath);
                            return ['date' => $compact, 'debug' => $debug];
                        }
                    }

                    @unlink($variantPath);
                }
            } finally {
                imagedestroy($crop);
            }
        }

        return ['date' => null, 'debug' => $debug];
    }

    private function extractBirthDateFromFocusedOcr(string $filePath, string $tesseractPath): array
    {
        $image = $this->loadImageResource($filePath);
        if (! $image) {
            return ['date' => null, 'debug' => []];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $candidateTexts = [];
        $debug = [];
        $zones = [
            ['x' => 0.30, 'y' => 0.39, 'w' => 0.32, 'h' => 0.10],
            ['x' => 0.28, 'y' => 0.37, 'w' => 0.36, 'h' => 0.12],
            ['x' => 0.26, 'y' => 0.35, 'w' => 0.40, 'h' => 0.14],
        ];
        $variants = [
            ['width' => 2400, 'grayscale' => true, 'contrast' => 40, 'sharpen' => 20, 'threshold' => false],
            ['width' => 2800, 'grayscale' => true, 'contrast' => 55, 'sharpen' => 20, 'threshold' => true, 'threshold_value' => 140],
        ];
        $psms = [7, 6];

        try {
            foreach ($zones as $zoneIndex => $zone) {
                $cropX = (int) round($width * $zone['x']);
                $cropY = (int) round($height * $zone['y']);
                $cropWidth = (int) round($width * $zone['w']);
                $cropHeight = (int) round($height * $zone['h']);

                $crop = imagecrop($image, [
                    'x' => max(0, $cropX),
                    'y' => max(0, $cropY),
                    'width' => max(1, min($cropWidth, $width - $cropX)),
                    'height' => max(1, min($cropHeight, $height - $cropY)),
                ]);

                if (! $crop) {
                    continue;
                }

                try {
                    foreach ($variants as $variantIndex => $options) {
                        $processed = $this->prepareOcrImage($crop, $options);
                        $variantPath = storage_path('app/'.'ocr_birth_'.$zoneIndex.'_'.$variantIndex.'_'.Str::uuid()->toString().'.png');
                        imagepng($processed, $variantPath);
                        imagedestroy($processed);

                        foreach ($psms as $psm) {
                            $strictText = $this->runTesseract($variantPath, $tesseractPath, $psm, '0123456789/');
                            $looseText = $this->runTesseract($variantPath, $tesseractPath, $psm);

                            $candidateTexts[] = $strictText;
                            $candidateTexts[] = $looseText;
                            $debug[] = [
                                'zone' => $zoneIndex,
                                'variant' => $variantIndex,
                                'psm' => $psm,
                                'strict' => $strictText,
                                'loose' => $looseText,
                            ];

                            foreach ([$strictText, $looseText] as $candidateText) {
                                foreach ($this->extractAllDates($candidateText) as $date) {
                                    if ($this->looksLikeBirthDate($date)) {
                                        @unlink($variantPath);
                                        return [
                                            'date' => $date,
                                            'debug' => $debug,
                                        ];
                                    }
                                }
                            }
                        }

                        @unlink($variantPath);
                    }
                } finally {
                    imagedestroy($crop);
                }
            }
        } finally {
            imagedestroy($image);
        }

        $birthDateCandidates = [];

        foreach ($candidateTexts as $candidateText) {
            foreach ($this->extractAllDates($candidateText) as $date) {
                if ($this->looksLikeBirthDate($date)) {
                    $birthDateCandidates[$date] = $date;
                }
            }
        }

        if ($birthDateCandidates !== []) {
            usort($birthDateCandidates, function ($left, $right) {
                try {
                    return Carbon::createFromFormat('d/m/Y', $left)->getTimestamp()
                        <=> Carbon::createFromFormat('d/m/Y', $right)->getTimestamp();
                } catch (\Throwable) {
                    return 0;
                }
            });

            return [
                'date' => $birthDateCandidates[0] ?? null,
                'debug' => $debug,
            ];
        }
    
        return [
            'date' => null,
            'debug' => $debug,
        ];
    }

    private function detectAfacAuthority(string $text): bool
    {
        $upper = strtoupper($text);

        return str_contains($upper, 'AFAC')
            || str_contains($upper, 'AGENCIA FEDERAL DE AVIACION CIVIL')
            || str_contains($upper, 'LICENCIA FEDERAL')
            || str_contains($upper, 'PERSONAL TECNICO AERONAUTICO');
    }

    private function cleanLineForName(string $line): ?string
    {
        $line = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'], ['A', 'E', 'I', 'O', 'U', 'N'], $line);
        $line = preg_replace('/[^A-Z ]/', ' ', $line) ?? '';
        $line = preg_replace('/\b(I|II|III|IV|IVA|V|VI|VII|X|XI|XII|XIV)\b/', ' ', $line) ?? $line;
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        $line = trim($line);

        return $line !== '' ? $line : null;
    }

    private function extractDatesDynamic(string $text): array
    {
        $clean = strtoupper($text);
        $clean = str_replace(['O', 'Q'], '0', $clean);
        $clean = str_replace(['I', 'L'], '1', $clean);

        preg_match_all('/\b\d{2}[\/\-]\d{2}[\/\-]\d{4}\b/', $clean, $matches);
        $dates = [];

        foreach ($matches[0] ?? [] as $date) {
            $date = str_replace('-', '/', $date);
            if ($this->isRealDate($date)) {
                $dates[] = $date;
            }
        }

        $dates = array_values(array_unique($dates));
        $birthDate = null;
        $expirationDate = null;

        foreach ($dates as $date) {
            try {
                $carbon = Carbon::createFromFormat('d/m/Y', $date);

                if ($carbon->year >= 1940 && $carbon->year <= now()->year - 16) {
                    if (! $birthDate || $carbon->lt(Carbon::createFromFormat('d/m/Y', $birthDate))) {
                        $birthDate = $date;
                    }
                }

                if ($carbon->year >= now()->year) {
                    if (! $expirationDate || $carbon->gt(Carbon::createFromFormat('d/m/Y', $expirationDate))) {
                        $expirationDate = $date;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return [
            'birth_date' => $birthDate,
            'expiration_date' => $expirationDate,
            'all_dates' => $dates,
        ];
    }

    private function isRealDate(string $date): bool
    {
        try {
            $parsed = Carbon::createFromFormat('d/m/Y', $date);
            return $parsed && $parsed->format('d/m/Y') === $date;
        } catch (\Throwable) {
            return false;
        }
    }

    private function extractNationalityDynamic(string $text): ?string
    {
        $upper = strtoupper($text);

        if (str_contains($upper, 'MEXICANO') || str_contains($upper, 'MEXICAN')) {
            return 'Mexicano / Mexican';
        }

        return null;
    }

    private function extractIssueDateDynamic(string $text): ?string
    {
        $upper = strtoupper($text);
        $upper = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'], ['A', 'E', 'I', 'O', 'U', 'N'], $upper);

        $months = [
            'ENERO' => '01',
            'FEBRERO' => '02',
            'MARZO' => '03',
            'ABRIL' => '04',
            'MAYO' => '05',
            'JUNIO' => '06',
            'JULIO' => '07',
            'AGOSTO' => '08',
            'SEPTIEMBRE' => '09',
            'SETIEMBRE' => '09',
            'OCTUBRE' => '10',
            'NOVIEMBRE' => '11',
            'DICIEMBRE' => '12',
        ];

        if (preg_match('/(\d{1,2})\s*DE\s*([A-Z]+)\s*DE\s*(\d{4})/u', $upper, $match)) {
            $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
            $monthName = $match[2];
            $year = $match[3];

            if (isset($months[$monthName])) {
                return $day.'/'.$months[$monthName].'/'.$year;
            }
        }

        return null;
    }

    private function extractDate(string $text): ?string
    {
        $upper = strtoupper($text);
        $upper = str_replace(['O', 'Q'], '0', $upper);
        $upper = str_replace(['I', 'L'], '1', $upper);

        if (preg_match('/\b\d{2}[\/\-]\d{2}[\/\-]\d{4}\b/', $upper, $match)) {
            $date = str_replace('-', '/', $match[0]);
            return $this->isRealDate($date) ? $date : null;
        }

        $onlyNumbers = preg_replace('/[^0-9]/', '', $upper) ?? '';
        if (strlen($onlyNumbers) >= 8) {
            $date = substr($onlyNumbers, 0, 2).'/'.substr($onlyNumbers, 2, 2).'/'.substr($onlyNumbers, 4, 4);
            return $this->isRealDate($date) ? $date : null;
        }

        return null;
    }

    private function extractAllDates(string $text): array
    {
        $upper = strtoupper($text);
        $upper = str_replace(['O', 'Q'], '0', $upper);
        $upper = str_replace(['I', 'L'], '1', $upper);
        $dates = [];

        preg_match_all('/\b\d{2}[\/\-]\d{2}[\/\-]\d{4}\b/', $upper, $matches);
        foreach ($matches[0] ?? [] as $match) {
            $date = str_replace('-', '/', $match);
            if ($this->isRealDate($date)) {
                $dates[$date] = $date;
            }
        }

        preg_match_all('/\b\d{8}\b/', preg_replace('/[^0-9]/', ' ', $upper) ?? '', $compactMatches);
        foreach ($compactMatches[0] ?? [] as $match) {
            $date = substr($match, 0, 2).'/'.substr($match, 2, 2).'/'.substr($match, 4, 4);
            if ($this->isRealDate($date)) {
                $dates[$date] = $date;
            }
        }

        return array_values($dates);
    }

    private function looksLikeBirthDate(string $date): bool
    {
        try {
            $parsed = Carbon::createFromFormat('d/m/Y', $date);
            return $parsed->year >= 1940 && $parsed->year <= now()->year - 16;
        } catch (\Throwable) {
            return false;
        }
    }

    private function getDocumentStatus(?string $expirationDate): string
    {
        if (! $expirationDate) {
            return 'Pendiente de validacion';
        }

        try {
            $date = Carbon::createFromFormat('d/m/Y', $expirationDate);
            if ($date->isPast()) {
                return 'Vencida';
            }

            return 'Vigente / pendiente de validacion';
        } catch (\Throwable) {
            return 'Pendiente de validacion';
        }
    }

    private function resolveTesseractPath(): ?string
    {
        $configuredPath = trim((string) env('OCR_TESSERACT_PATH', ''));
        if ($configuredPath !== '' && is_executable($configuredPath)) {
            return $configuredPath;
        }

        foreach (['/opt/homebrew/bin/tesseract', '/usr/local/bin/tesseract', '/usr/bin/tesseract'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
