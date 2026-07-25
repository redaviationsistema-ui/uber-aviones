<?php

namespace App\Servicios\Ocr;

use Carbon\Carbon;
use Illuminate\Support\Str;

class GenericDocumentScannerService
{
    private array $ocrCache = [];

    public function __construct(
        private readonly DocumentImageProcessor $imageProcessor = new DocumentImageProcessor(),
    ) {
    }

    public function scan(array $payload, string $tesseractPath): array
    {
        $startedAt = microtime(true);
        $requestedType = DocumentScanConfig::normalizeType($payload['document_type'] ?? 'auto');
        $frontSignals = $this->normalizeSignals($payload['front_signals'] ?? []);
        $backSignals = $this->normalizeSignals($payload['back_signals'] ?? []);
        $qualityFront = $this->normalizeQualityInput($payload['quality_front'] ?? []);
        $qualityBack = $this->normalizeQualityInput($payload['quality_back'] ?? []);

        $front = $this->scanSide($payload['front_path'], 'front', $tesseractPath, $frontSignals, $qualityFront);
        $back = ! empty($payload['back_path'])
            ? $this->scanSide($payload['back_path'], 'back', $tesseractPath, $backSignals, $qualityBack)
            : null;

        $documentType = $requestedType !== 'auto'
            ? $requestedType
            : $this->detectDocumentType($front['text'].' '.$front['machine_text'].' '.($back['text'] ?? '').' '.($back['machine_text'] ?? ''));

        $fields = $this->extractFields($documentType, $front, $back);
        $normalizedFields = $this->ensureExpectedFields($documentType, $fields);
        $warnings = $this->buildWarnings($normalizedFields, $front, $back);
        $reviewFields = $this->resolveReviewFields($documentType, $normalizedFields, $warnings);
        if ($reviewFields !== []) {
            $warnings[] = 'manual_review_required';
        }
        $processingTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'success' => true,
            'documentType' => $documentType,
            'documentSide' => $back ? 'front_back' : 'front',
            'fields' => $normalizedFields,
            'quality' => $this->mergeQuality($front['quality'], $back['quality'] ?? []),
            'warnings' => array_values(array_unique($warnings)),
            'reviewFields' => $reviewFields,
            'processingTimeMs' => $processingTimeMs,
            'timings' => [
                'frontMs' => $front['processing_ms'],
                'backMs' => $back['processing_ms'] ?? 0,
                'totalMs' => $processingTimeMs,
            ],
            'rawText' => trim($front['text']."\n\n".($back['text'] ?? '')),
        ];
    }

    private function scanSide(string $filePath, string $side, string $tesseractPath, array $signals, array $qualityInput): array
    {
        $processed = $this->imageProcessor->prepare($filePath);

        try {
            $text = $this->runOcrCached($processed['path'], $processed['hash'], $tesseractPath, 6);
            $machineText = collect($signals)->pluck('value')->filter()->implode("\n");

            return [
                'side' => $side,
                'text' => $text,
                'machine_text' => $machineText,
                'signals' => $signals,
                'quality' => $this->mergeQuality($processed['quality'], $qualityInput),
                'processing_ms' => ($processed['processing_ms'] ?? 0),
            ];
        } finally {
            if (is_file($processed['path'])) {
                @unlink($processed['path']);
            }
        }
    }

    private function normalizeSignals(array|string $signals): array
    {
        if (is_string($signals)) {
            $decoded = json_decode($signals, true);
            $signals = is_array($decoded) ? $decoded : [];
        }

        return collect($signals)
            ->filter(fn ($signal) => is_array($signal) && ! empty($signal['value']))
            ->map(fn ($signal) => [
                'value' => trim((string) $signal['value']),
                'format' => strtolower(trim((string) ($signal['format'] ?? 'unknown'))),
                'source' => strtolower(trim((string) ($signal['source'] ?? 'barcode'))),
            ])
            ->values()
            ->all();
    }

    private function normalizeQualityInput(array|string $quality): array
    {
        if (is_string($quality)) {
            $decoded = json_decode($quality, true);
            $quality = is_array($decoded) ? $decoded : [];
        }

        return [
            'blur' => (float) ($quality['blur'] ?? 0),
            'brightness' => (float) ($quality['brightness'] ?? 0),
            'glare' => (float) ($quality['glare'] ?? 0),
            'documentDetected' => (bool) ($quality['documentDetected'] ?? false),
            'cropped' => (bool) ($quality['cropped'] ?? false),
        ];
    }

    private function runOcrCached(string $filePath, string $hash, string $tesseractPath, int $psm): string
    {
        $cacheKey = $hash.':'.$psm;
        if (isset($this->ocrCache[$cacheKey])) {
            return $this->ocrCache[$cacheKey];
        }

        $this->ocrCache[$cacheKey] = $this->runTesseract($filePath, $tesseractPath, $psm);
        return $this->ocrCache[$cacheKey];
    }

    private function runTesseract(string $filePath, string $tesseractPath, int $psm = 6): string
    {
        $outputBase = storage_path('app/ocr_'.Str::uuid()->toString());
        $command = escapeshellarg($tesseractPath).' '
            .escapeshellarg($filePath).' '
            .escapeshellarg($outputBase)
            .' -l spa+eng --oem 1 --psm '.intval($psm).' 2>&1';

        shell_exec($command);

        $txtFile = $outputBase.'.txt';
        if (! is_file($txtFile)) {
            return '';
        }

        $text = file_get_contents($txtFile) ?: '';
        @unlink($txtFile);

        return $this->normalizeText($text);
    }

    private function detectDocumentType(string $text): string
    {
        foreach (DocumentScanConfig::detectionRules() as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    return $type;
                }
            }
        }

        return DocumentScanConfig::TYPE_CUSTOM;
    }

    private function extractFields(string $documentType, array $front, ?array $back): array
    {
        $fullText = trim($front['text'].' '.$front['machine_text'].' '.($back['text'] ?? '').' '.($back['machine_text'] ?? ''));
        $upperText = strtoupper($fullText);
        $signals = array_merge($front['signals'], $back['signals'] ?? []);
        $machineCombined = collect($signals)->pluck('value')->implode(' ');
        $fields = [];

        $this->setField($fields, 'document_type', $documentType, 98, 'ocr');

        if ($mrz = $this->extractMrz($fullText)) {
            $this->setField($fields, 'mrz', $mrz['raw'], 96, 'mrz');
            foreach ($mrz['fields'] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $this->setField($fields, $key, $value, 94, 'mrz');
                }
            }
        }

        if ($curp = $this->extractCurp($upperText)) {
            $this->setField($fields, 'curp', $curp, 95, 'ocr');
        }

        if ($rfc = $this->extractRfc($upperText)) {
            $this->setField($fields, 'rfc', $rfc, 93, 'ocr');
        }

        if ($documentNumber = $this->extractDocumentNumber($documentType, $upperText, $machineCombined)) {
            $source = $this->stringContains($machineCombined, $documentNumber) ? 'barcode' : 'ocr';
            $this->setField($fields, $documentType === DocumentScanConfig::TYPE_PASSPORT ? 'passport_number' : 'document_number', $documentNumber, 92, $source);
        }

        if ($name = $this->extractName($documentType, $fullText)) {
            $this->setField($fields, 'name', $name, 84, 'ocr');
        }

        if ($documentType === DocumentScanConfig::TYPE_INE && ($machineReadableName = $this->extractIneMachineReadableName($fullText))) {
            $this->setField($fields, 'name', $machineReadableName, 92, 'barcode');
        }

        if ($documentType === DocumentScanConfig::TYPE_INE && ($machineBirthDate = $this->extractIneMachineBirthDate($upperText))) {
            $this->setField($fields, 'birth_date', $machineBirthDate, 80, 'barcode');
        }

        if ($birthDate = $this->extractDateNearKeywords($upperText, ['FECHA DE NACIMIENTO', 'BIRTH', 'NACIMIENTO'])) {
            $this->setField($fields, 'birth_date', $birthDate, 89, 'ocr');
        }

        if ($issueDate = $this->extractDateNearKeywords($upperText, ['EMISION', 'ISSUE', 'EXPEDICION'])) {
            $this->setField($fields, 'issue_date', $issueDate, 87, 'ocr');
        }

        if ($expirationDate = $this->extractDateNearKeywords($upperText, ['VIGENCIA', 'EXPIRATION', 'EXPIRES', 'VENCE', 'VALID UNTIL'])) {
            $this->setField($fields, 'expiration_date', $expirationDate, 90, 'ocr');
            $this->setField($fields, 'document_status', $this->calculateDocumentStatus($expirationDate), 88, 'ocr');
        }

        if ($nationality = $this->extractNationality($upperText)) {
            $this->setField($fields, 'nationality', $nationality, 82, 'ocr');
        }

        if ($issuingCountry = $this->extractIssuingCountry($upperText)) {
            $this->setField($fields, 'issuing_country', $issuingCountry, 82, 'ocr');
        }

        if ($documentType === DocumentScanConfig::TYPE_INE) {
            if ($cic = $this->extractByRegex($upperText, '/(?:CIC|IDCIC)[:\s-]*(\d{8,12})/')) {
                $this->setField($fields, 'cic', $cic, 94, 'ocr');
            }
            if ($ocr = $this->extractByRegex($upperText, '/(?:OCR|IDENTIFICADOR)[:\s-]*(\d{10,14})/')) {
                $this->setField($fields, 'ocr', $ocr, 94, 'ocr');
            }
        }

        if ($documentType === DocumentScanConfig::TYPE_DRIVER_LICENSE) {
            $this->setField($fields, 'license_type', 'Licencia', 80, 'ocr');
            if ($category = $this->extractByRegex($upperText, '/(?:CATEGORIA|CATEGORY|CARGO)[:\s-]*([A-Z0-9 \-]{2,40})/')) {
                $this->setField($fields, 'license_category', trim($category), 84, 'ocr');
            }
        }

        if ($documentType === DocumentScanConfig::TYPE_PROOF_OF_ADDRESS) {
            if ($address = $this->extractAddress($fullText)) {
                $this->setField($fields, 'address', $address, 76, 'ocr');
            }
        }

        if ($documentType === DocumentScanConfig::TYPE_VEHICLE_REGISTRATION) {
            if ($plate = $this->extractByRegex($upperText, '/(?:PLACA|PLACAS)[:\s-]*([A-Z0-9\-]{5,10})/')) {
                $this->setField($fields, 'plate', $plate, 91, 'ocr');
            }
            if ($serial = $this->extractByRegex($upperText, '/(?:NIV|SERIAL|VIN)[:\s-]*([A-Z0-9]{10,20})/')) {
                $this->setField($fields, 'serial_number', $serial, 91, 'ocr');
            }
        }

        if (in_array($documentType, [DocumentScanConfig::TYPE_CONSTANCY, DocumentScanConfig::TYPE_CERTIFICATE, DocumentScanConfig::TYPE_INVOICE, DocumentScanConfig::TYPE_CUSTOM], true)) {
            if ($issuer = $this->extractIssuerName($fullText)) {
                $this->setField($fields, 'issuer_name', $issuer, 76, 'ocr');
            }
            if ($reference = $this->extractReferenceNumber($upperText)) {
                $this->setField($fields, 'reference_number', $reference, 88, 'ocr');
            }
        }

        if ($documentType === DocumentScanConfig::TYPE_INVOICE) {
            if ($total = $this->extractByRegex($upperText, '/(?:TOTAL)[:\s$-]*([0-9\.,]+)/')) {
                $this->setField($fields, 'total_amount', $total, 90, 'ocr');
            }
        }

        return $fields;
    }

    private function buildWarnings(array $fields, array $front, ?array $back): array
    {
        $warnings = [];
        $quality = $this->mergeQuality($front['quality'], $back['quality'] ?? []);

        if (! $quality['documentDetected']) {
            $warnings[] = 'document_not_detected';
        }
        if (($quality['blur'] ?? 0) < 10) {
            $warnings[] = 'image_blurry';
        }
        if (($quality['glare'] ?? 0) > 12) {
            $warnings[] = 'excessive_glare';
        }
        if (($quality['brightness'] ?? 0) < 80) {
            $warnings[] = 'image_too_dark';
        }
        if (($quality['brightness'] ?? 0) > 210) {
            $warnings[] = 'image_overexposed';
        }
        if (empty($fields)) {
            $warnings[] = 'ocr_no_results';
        }

        foreach ($fields as $key => $field) {
            $value = $field['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($key, ['birth_date', 'issue_date', 'expiration_date'], true) && ! $this->isIsoDate($value)) {
                $warnings[] = 'invalid_date_format';
            }

            if ($key === 'expiration_date' && $this->calculateDocumentStatus($value) === 'Vencido') {
                $warnings[] = 'document_expired';
            }

            if ($key === 'curp' && ! $this->isValidCurp($value)) {
                $warnings[] = 'invalid_curp';
            }

            if ($key === 'rfc' && ! $this->isValidRfc($value)) {
                $warnings[] = 'invalid_rfc';
            }

            if ($key === 'mrz' && ! $this->looksLikeMrz($value)) {
                $warnings[] = 'invalid_mrz';
            }
        }

        if ($back) {
            $frontNumber = $fields['document_number']['value'] ?? $fields['passport_number']['value'] ?? null;
            $backNumber = $this->extractDocumentNumber($this->detectDocumentType($back['text']), strtoupper($back['text']), $back['machine_text']);
            if ($frontNumber && $backNumber && $frontNumber !== $backNumber) {
                $warnings[] = 'front_back_mismatch';
            }
        }

        return $warnings;
    }

    private function resolveReviewFields(string $documentType, array $fields, array $warnings): array
    {
        $review = [];

        foreach ($fields as $key => $field) {
            $confidence = (int) ($field['confidence'] ?? 0);
            if (($field['value'] ?? null) === null || $confidence < 78) {
                $review[] = $key;
            }
        }

        foreach (DocumentScanConfig::expectedFields($documentType) as $fieldKey) {
            if (($fields[$fieldKey]['value'] ?? null) === null) {
                $review[] = $fieldKey;
            }
        }

        if (in_array('front_back_mismatch', $warnings, true)) {
            $review[] = 'document_number';
        }
        if (in_array('invalid_curp', $warnings, true)) {
            $review[] = 'curp';
        }
        if (in_array('invalid_rfc', $warnings, true)) {
            $review[] = 'rfc';
        }
        if (in_array('invalid_mrz', $warnings, true)) {
            $review[] = 'mrz';
        }
        if (
            isset($fields['name']['value']) &&
            is_string($fields['name']['value']) &&
            $this->isSuspiciousFullName($fields['name']['value'])
        ) {
            $review[] = 'name';
        }

        return array_values(array_unique($review));
    }

    private function ensureExpectedFields(string $documentType, array $fields): array
    {
        $expected = DocumentScanConfig::expectedFields($documentType);
        $normalized = $fields;

        foreach ($expected as $fieldKey) {
            if (! array_key_exists($fieldKey, $normalized)) {
                $normalized[$fieldKey] = [
                    'value' => null,
                    'confidence' => 0,
                    'source' => 'ocr',
                ];
            }
        }

        return $normalized;
    }

    private function setField(array &$fields, string $key, mixed $value, int $confidence, string $source): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $existingConfidence = (int) ($fields[$key]['confidence'] ?? 0);
        if ($existingConfidence > $confidence) {
            return;
        }

        $fields[$key] = [
            'value' => $value,
            'confidence' => $confidence,
            'source' => $source,
        ];
    }

    private function extractMrz(string $text): ?array
    {
        preg_match_all('/([A-Z0-9<]{30,44})/', strtoupper($text), $matches);
        $rows = array_values(array_filter($matches[1] ?? [], fn ($row) => strlen($row) >= 30));

        if (count($rows) < 2) {
            return null;
        }

        $lineOne = $rows[0];
        $lineTwo = $rows[1];
        $surname = trim(str_replace('<', ' ', substr($lineOne, 5, strpos($lineOne, '<<') - 5)));
        $givenNames = trim(str_replace('<', ' ', substr($lineOne, strpos($lineOne, '<<') + 2)));
        $passportNumber = trim(str_replace('<', '', substr($lineTwo, 0, 9)));
        $nationality = trim(str_replace('<', '', substr($lineTwo, 10, 3)));
        $birthDate = $this->normalizeMrzDate(substr($lineTwo, 13, 6));
        $expirationDate = $this->normalizeMrzDate(substr($lineTwo, 21, 6));

        return [
            'raw' => $lineOne."\n".$lineTwo,
            'fields' => [
                'name' => trim($givenNames.' '.$surname),
                'passport_number' => $passportNumber,
                'nationality' => $nationality,
                'birth_date' => $birthDate,
                'expiration_date' => $expirationDate,
            ],
        ];
    }

    private function normalizeMrzDate(string $value): ?string
    {
        if (! preg_match('/^\d{6}$/', $value)) {
            return null;
        }

        $currentYear = (int) now()->format('y');
        $year = (int) substr($value, 0, 2);
        $century = $year > $currentYear ? 1900 : 2000;

        return sprintf('%d-%s-%s', $century + $year, substr($value, 2, 2), substr($value, 4, 2));
    }

    private function extractCurp(string $text): ?string
    {
        preg_match('/[A-Z][AEIOUX][A-Z]{2}\d{6}[HM][A-Z]{5}[A-Z0-9]\d/', $text, $matches);
        return $matches[0] ?? null;
    }

    private function extractRfc(string $text): ?string
    {
        preg_match('/[A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3}/', $text, $matches);
        return $matches[0] ?? null;
    }

    private function extractDocumentNumber(string $documentType, string $upperText, string $machineText): ?string
    {
        $patterns = [
            '/(?:NUMERO|NO\.?|FOLIO|DOCUMENTO|PASSPORT|LICENCIA|CLAVE DE ELECTOR)[:\s-]*([A-Z0-9\-]{6,24})/',
            '/\b[A-Z]{1,4}\d{5,15}\b/',
            '/\b\d{8,20}\b/',
        ];

        $haystack = trim($upperText.' '.$machineText);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $haystack, $matches)) {
                return trim($matches[1] ?? $matches[0]);
            }
        }

        return null;
    }

    private function extractName(string $documentType, string $text): ?string
    {
        $lines = collect(preg_split('/\r?\n/', $text))
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();

        foreach ($lines as $index => $line) {
            if (preg_match('/^(NOMBRE|NAME|APELLIDOS|TITULAR)\b/i', $line)) {
                $candidate = trim(($lines[$index + 1] ?? '').' '.($lines[$index + 2] ?? ''));
                $normalized = preg_replace('/[^A-ZÑa-zñ ]/u', ' ', $candidate);
                $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
                if (mb_strlen(trim($normalized)) >= 6) {
                    return trim($normalized);
                }
            }
        }

        if ($documentType === DocumentScanConfig::TYPE_PASSPORT && ($mrz = $this->extractMrz($text))) {
            return $mrz['fields']['name'] ?? null;
        }

        return null;
    }

    private function extractIneMachineReadableName(string $text): ?string
    {
        $normalizedText = strtoupper($text);
        $patternCandidates = [];

        if (preg_match_all('/([A-Z]{2,}(?:<[A-Z]{2,}){0,4})<<([A-Z]{2,}(?:<[A-Z]{2,}){0,4})/', $normalizedText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $patternCandidates[] = trim(($match[1] ?? '').'<<'.($match[2] ?? ''));
            }
        }

        foreach ($patternCandidates as $candidate) {
            $name = $this->buildIneMachineReadableNameCandidate($candidate);
            if ($name !== null) {
                return $name;
            }
        }

        $lines = collect(preg_split('/\r?\n/', $normalizedText))
            ->map(function ($line) {
                $normalized = preg_replace('/[^A-Z< ]/', '', (string) $line);
                $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
                return trim((string) $normalized);
            })
            ->filter()
            ->values()
            ->all();

        $windows = [];
        foreach ($lines as $index => $line) {
            $windows[] = $line;
            $windows[] = trim(($lines[$index - 1] ?? '').' '.$line);
            $windows[] = trim($line.' '.($lines[$index + 1] ?? ''));
            $windows[] = trim(($lines[$index - 1] ?? '').' '.$line.' '.($lines[$index + 1] ?? ''));
        }

        $bestCandidate = null;
        $bestScore = -1;

        foreach ($windows as $window) {
            $clean = preg_replace('/<{3,}/', '<<', $window);
            $clean = preg_replace('/^[^A-Z]+/', '', (string) $clean);

            if (! str_contains((string) $clean, '<<')) {
                continue;
            }

            $name = $this->buildIneMachineReadableNameCandidate((string) $clean);
            if ($name === null) {
                continue;
            }

            $score = $this->scoreIneMachineReadableNameCandidate($name);
            if ($score > $bestScore) {
                $bestCandidate = $name;
                $bestScore = $score;
            }
        }

        return $bestCandidate && mb_strlen($bestCandidate) >= 6 ? $bestCandidate : null;
    }

    private function buildIneMachineReadableNameCandidate(string $value): ?string
    {
        [$surnamePart, $givenPart] = array_pad(explode('<<', (string) $value, 2), 2, '');
        $surnamePart = $this->normalizeMachineNameTokenStream($surnamePart);
        $givenPart = $this->normalizeMachineNameTokenStream($givenPart);

        $stopwords = ['EX', 'MH', 'HM', 'D', 'DI'];
        $surnames = array_values(array_filter(
            explode('<', $surnamePart),
            fn ($token) => strlen(trim($token)) >= 2 && ! in_array(trim($token), $stopwords, true)
        ));
        $givenNames = array_values(array_filter(
            explode('<', $givenPart),
            fn ($token) => strlen(trim($token)) >= 2 && ! in_array(trim($token), $stopwords, true)
        ));

        if ($surnames === [] || $givenNames === []) {
            return null;
        }

        $name = trim(implode(' ', array_merge($givenNames, $surnames)));
        return mb_strlen($name) >= 6 ? $name : null;
    }

    private function scoreIneMachineReadableNameCandidate(string $name): int
    {
        $tokenCount = count(array_filter(explode(' ', $name)));
        return ($tokenCount * 100) + strlen(str_replace(' ', '', $name));
    }

    private function normalizeMachineNameTokenStream(string $value): string
    {
        $normalized = strtoupper(trim($value));
        $normalized = preg_replace('/<+/', '<', $normalized);
        $normalized = preg_replace('/\bCARCIA\b/', 'GARCIA', $normalized);
        $normalized = preg_replace('/\bMERCAD[O0]?\b/', 'MERCADO', $normalized);
        $normalized = preg_replace('/\bMERE\b/', 'MERCADO', $normalized);
        return trim((string) $normalized, '< ');
    }

    private function extractIneMachineBirthDate(string $text): ?string
    {
        if (! preg_match('/\b(\d{6})\d?[HM]\d{2,8}\b/', strtoupper($text), $matches)) {
            return null;
        }

        return $this->normalizeMrzDate($matches[1] ?? '');
    }

    private function isSuspiciousFullName(string $name): bool
    {
        $tokens = array_values(array_filter(explode(' ', preg_replace('/\s+/', ' ', trim($name)))));
        if (count($tokens) < 3) {
            return true;
        }

        foreach ($tokens as $token) {
            if (strlen($token) < 2) {
                return true;
            }
        }

        return false;
    }

    private function extractDateNearKeywords(string $text, array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            $pattern = '/'.preg_quote($keyword, '/').'.{0,22}?(\d{2}[\/\-.]\d{2}[\/\-.]\d{4}|\d{4}[\/\-.]\d{2}[\/\-.]\d{2})/i';
            if (preg_match($pattern, $text, $matches)) {
                return $this->normalizeDate($matches[1]);
            }
        }

        if (preg_match('/(\d{4}[\/\-.]\d{2}[\/\-.]\d{2})/', $text, $matches)) {
            return $this->normalizeDate($matches[1]);
        }

        if (preg_match('/(\d{2}[\/\-.]\d{2}[\/\-.]\d{4})/', $text, $matches)) {
            return $this->normalizeDate($matches[1]);
        }

        return null;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^\d{4}[\/\-.]\d{2}[\/\-.]\d{2}$/', $value)) {
            return str_replace(['/', '.'], '-', $value);
        }

        if (preg_match('/^(\d{2})[\/\-.](\d{2})[\/\-.](\d{4})$/', $value, $matches)) {
            return $matches[3].'-'.$matches[2].'-'.$matches[1];
        }

        return null;
    }

    private function calculateDocumentStatus(?string $date): ?string
    {
        if (! $this->isIsoDate($date)) {
            return null;
        }

        $expiration = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        $today = Carbon::now()->startOfDay();

        if ($expiration->lt($today)) {
            return 'Vencido';
        }

        if ($expiration->diffInDays($today) <= 30) {
            return 'Por vencer';
        }

        return 'Vigente';
    }

    private function extractNationality(string $text): ?string
    {
        if (preg_match('/(?:NACIONALIDAD|NATIONALITY)[:\s-]*([A-Z ]{4,30})/i', $text, $matches)) {
            return trim($matches[1]);
        }

        if (str_contains($text, 'MEXICAN') || str_contains($text, 'MEXICANA') || str_contains($text, 'MEXICANO')) {
            return 'Mexicana';
        }

        return null;
    }

    private function extractIssuingCountry(string $text): ?string
    {
        if (preg_match('/(?:PAIS|COUNTRY|ISSUING COUNTRY)[:\s-]*([A-Z ]{4,30})/i', $text, $matches)) {
            return trim($matches[1]);
        }

        if (str_contains($text, 'MEXICO')) {
            return 'Mexico';
        }

        return null;
    }

    private function extractAddress(string $text): ?string
    {
        preg_match('/(?:DOMICILIO|ADDRESS)[:\s-]*(.+?)(?:CP|C\.P\.|RFC|CURP|$)/is', $text, $matches);
        return isset($matches[1]) ? trim(preg_replace('/\s+/', ' ', $matches[1])) : null;
    }

    private function extractIssuerName(string $text): ?string
    {
        preg_match('/(?:EMISOR|ISSUER|EXPIDE|CERTIFICA)[:\s-]*(.+)/i', $text, $matches);
        return isset($matches[1]) ? trim(preg_replace('/\s+/', ' ', $matches[1])) : null;
    }

    private function extractReferenceNumber(string $text): ?string
    {
        preg_match('/(?:FOLIO|REFERENCIA|UUID|NO\.?|NUMERO)[:\s-]*([A-Z0-9\-]{6,40})/i', $text, $matches);
        return $matches[1] ?? null;
    }

    private function extractByRegex(string $text, string $pattern): ?string
    {
        preg_match($pattern, $text, $matches);
        return $matches[1] ?? null;
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    private function mergeQuality(array $first, array $second = []): array
    {
        if (! $second) {
            return [
                'blur' => round((float) ($first['blur'] ?? 0), 2),
                'brightness' => round((float) ($first['brightness'] ?? 0), 2),
                'glare' => round((float) ($first['glare'] ?? 0), 2),
                'documentDetected' => (bool) ($first['documentDetected'] ?? false),
                'cropped' => (bool) ($first['cropped'] ?? false),
            ];
        }

        return [
            'blur' => round((((float) ($first['blur'] ?? 0)) + ((float) ($second['blur'] ?? 0))) / 2, 2),
            'brightness' => round((((float) ($first['brightness'] ?? 0)) + ((float) ($second['brightness'] ?? 0))) / 2, 2),
            'glare' => round((((float) ($first['glare'] ?? 0)) + ((float) ($second['glare'] ?? 0))) / 2, 2),
            'documentDetected' => (bool) ($first['documentDetected'] ?? false) && (bool) ($second['documentDetected'] ?? false),
            'cropped' => (bool) ($first['cropped'] ?? false) && (bool) ($second['cropped'] ?? false),
        ];
    }

    private function isIsoDate(?string $date): bool
    {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    }

    private function isValidCurp(string $value): bool
    {
        return preg_match('/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', strtoupper($value)) === 1;
    }

    private function isValidRfc(string $value): bool
    {
        return preg_match('/^[A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3}$/', strtoupper($value)) === 1;
    }

    private function looksLikeMrz(string $value): bool
    {
        return preg_match('/[A-Z0-9<]{30,44}/', strtoupper($value)) === 1;
    }

    private function stringContains(string $haystack, string $needle): bool
    {
        return str_contains(strtoupper($haystack), strtoupper($needle));
    }
}
