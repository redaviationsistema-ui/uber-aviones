<?php

namespace App\Servicios\Ocr;

class DocumentScanConfig
{
    public const TYPE_INE = 'ine';
    public const TYPE_PASSPORT = 'passport';
    public const TYPE_DRIVER_LICENSE = 'driver_license';
    public const TYPE_PROOF_OF_ADDRESS = 'proof_of_address';
    public const TYPE_VISA = 'visa';
    public const TYPE_VEHICLE_REGISTRATION = 'vehicle_registration';
    public const TYPE_CONSTANCY = 'constancy';
    public const TYPE_CERTIFICATE = 'certificate';
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_CUSTOM = 'custom';

    public static function normalizeType(?string $type): string
    {
        $value = strtolower(trim((string) $type));

        return match ($value) {
            '', 'auto' => 'auto',
            'ine', 'identificacion', 'identificacion_oficial', 'official_id' => self::TYPE_INE,
            'passport', 'pasaporte' => self::TYPE_PASSPORT,
            'driver_license', 'license', 'licencia', 'licencia_de_conducir', 'licencia_de_sobrecargo' => self::TYPE_DRIVER_LICENSE,
            'proof_of_address', 'comprobante', 'comprobante_de_domicilio' => self::TYPE_PROOF_OF_ADDRESS,
            'visa' => self::TYPE_VISA,
            'vehicle_registration', 'tarjeta_de_circulacion' => self::TYPE_VEHICLE_REGISTRATION,
            'constancy', 'constancia' => self::TYPE_CONSTANCY,
            'certificate', 'certificado' => self::TYPE_CERTIFICATE,
            'invoice', 'factura' => self::TYPE_INVOICE,
            default => self::TYPE_CUSTOM,
        };
    }

    public static function supportedTypes(): array
    {
        return [
            self::TYPE_INE,
            self::TYPE_PASSPORT,
            self::TYPE_DRIVER_LICENSE,
            self::TYPE_PROOF_OF_ADDRESS,
            self::TYPE_VISA,
            self::TYPE_VEHICLE_REGISTRATION,
            self::TYPE_CONSTANCY,
            self::TYPE_CERTIFICATE,
            self::TYPE_INVOICE,
            self::TYPE_CUSTOM,
        ];
    }

    public static function expectedFields(string $type): array
    {
        return match (self::normalizeType($type)) {
            self::TYPE_INE => ['name', 'curp', 'document_number', 'birth_date', 'expiration_date', 'cic', 'ocr', 'document_status'],
            self::TYPE_PASSPORT => ['name', 'passport_number', 'nationality', 'birth_date', 'issue_date', 'expiration_date', 'issuing_country', 'mrz', 'document_status'],
            self::TYPE_DRIVER_LICENSE => ['name', 'document_number', 'license_type', 'license_category', 'nationality', 'birth_date', 'issue_date', 'expiration_date', 'issuing_country', 'document_status'],
            self::TYPE_PROOF_OF_ADDRESS => ['name', 'address', 'issuer_name', 'issue_date'],
            self::TYPE_VISA => ['name', 'document_number', 'nationality', 'birth_date', 'issue_date', 'expiration_date', 'issuing_country', 'visa_type', 'mrz', 'document_status'],
            self::TYPE_VEHICLE_REGISTRATION => ['name', 'document_number', 'plate', 'serial_number', 'issue_date', 'expiration_date', 'document_status'],
            self::TYPE_CONSTANCY => ['name', 'issuer_name', 'issue_date', 'reference_number'],
            self::TYPE_CERTIFICATE => ['name', 'issuer_name', 'issue_date', 'expiration_date', 'reference_number', 'document_status'],
            self::TYPE_INVOICE => ['issuer_name', 'rfc', 'issue_date', 'reference_number', 'total_amount'],
            default => ['name', 'document_number', 'issue_date', 'expiration_date', 'reference_number'],
        };
    }

    public static function detectionRules(): array
    {
        return [
            self::TYPE_INE => [
                '/\bCURP\b/i',
                '/\bCIC\b/i',
                '/\bINSTITUTO NACIONAL ELECTORAL\b/i',
                '/\bCLAVE DE ELECTOR\b/i',
            ],
            self::TYPE_PASSPORT => [
                '/\bPASSPORT\b/i',
                '/\bPASAPORTE\b/i',
                '/P<[A-Z]{3}/',
            ],
            self::TYPE_DRIVER_LICENSE => [
                '/\bLICENCIA\b/i',
                '/\bDRIVER\b/i',
                '/\bVIGENCIA\b/i',
            ],
            self::TYPE_PROOF_OF_ADDRESS => [
                '/\bDOMICILIO\b/i',
                '/\bSERVICIO\b/i',
                '/\bCFE\b/i',
                '/\bRECIBO\b/i',
            ],
            self::TYPE_VISA => [
                '/\bVISA\b/i',
                '/\bCATEGORY\b/i',
                '/V<[A-Z]{3}/',
            ],
            self::TYPE_VEHICLE_REGISTRATION => [
                '/\bCIRCULACION\b/i',
                '/\bPLACAS?\b/i',
                '/\bVEHICULO\b/i',
                '/\bNIV\b/i',
            ],
            self::TYPE_CONSTANCY => [
                '/\bCONSTANCIA\b/i',
            ],
            self::TYPE_CERTIFICATE => [
                '/\bCERTIFICADO\b/i',
                '/\bCERTIFICATE\b/i',
            ],
            self::TYPE_INVOICE => [
                '/\bFACTURA\b/i',
                '/\bUUID\b/i',
                '/\bRFC\b/i',
                '/\bCFDI\b/i',
            ],
        ];
    }
}
