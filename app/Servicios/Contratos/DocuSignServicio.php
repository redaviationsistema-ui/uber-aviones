<?php

namespace App\Servicios\Contratos;

use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Client\ApiClient;
use DocuSign\eSign\Client\ApiException;
use DocuSign\eSign\Configuration;
use DocuSign\eSign\Model\Document;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Model\RecipientViewRequest;
use DocuSign\eSign\Model\Recipients;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\Tabs;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DocuSignServicio
{
    private ?ApiClient $apiClient = null;

    public function estaConfigurado(): bool
    {
        return $this->configurationDiagnostics()['ok'];
    }

    public function configurationDiagnostics(): array
    {
        $privateKeySource = $this->privateKeySource();
        $privateKey = $this->resolvePrivateKeyContents(throwIfMissing: false);
        $privateKeyValidationError = $privateKey === null
            ? $this->detectInvalidPrivateKeyError()
            : $this->validatePrivateKey($privateKey);

        $checks = [
            'integration_key' => filled(config('services.docusign.integration_key')),
            'user_id' => filled(config('services.docusign.user_id')),
            'account_id' => filled(config('services.docusign.account_id')),
            'base_path' => filled(config('services.docusign.base_path')),
            'oauth_base_path' => filled(config('services.docusign.oauth_base_path')),
            'private_key' => $privateKey !== null && $privateKeyValidationError === null,
        ];

        $missing = collect($checks)
            ->filter(fn (bool $value) => $value === false)
            ->keys()
            ->values()
            ->all();

        return [
            'ok' => empty($missing),
            'checks' => $checks,
            'missing' => $missing,
            'private_key_source' => $privateKeySource,
            'private_key_path' => $this->resolvePrivateKeyPath(),
            'private_key_path_exists' => ($path = $this->resolvePrivateKeyPath()) !== '' && is_file($path),
            'private_key_valid' => $privateKey !== null && $privateKeyValidationError === null,
            'private_key_error' => $privateKeyValidationError,
            'base_path' => (string) config('services.docusign.base_path'),
            'oauth_base_path' => (string) config('services.docusign.oauth_base_path'),
            'frontend_url' => (string) config('services.docusign.frontend_url'),
            'return_path' => (string) config('services.docusign.return_path'),
        ];
    }

    public function buildConfigurationErrorMessage(): string
    {
        $diagnostics = $this->configurationDiagnostics();

        if ($diagnostics['ok']) {
            return 'DocuSign esta configurado correctamente.';
        }

        $labels = [
            'integration_key' => 'DOCUSIGN_INTEGRATION_KEY',
            'user_id' => 'DOCUSIGN_USER_ID',
            'account_id' => 'DOCUSIGN_ACCOUNT_ID',
            'base_path' => 'DOCUSIGN_BASE_PATH',
            'oauth_base_path' => 'DOCUSIGN_OAUTH_BASE_PATH',
            'private_key' => 'DOCUSIGN_PRIVATE_KEY o DOCUSIGN_PRIVATE_KEY_PATH',
        ];

        $missing = array_map(
            fn (string $key) => $labels[$key] ?? $key,
            $diagnostics['missing']
        );

        $message = 'DocuSign no esta configurado correctamente. Faltan o son invalidos: '.implode(', ', $missing).'.';

        if (! empty($diagnostics['private_key_error'])) {
            $message .= ' Error de llave privada: '.$diagnostics['private_key_error'];
        }

        return $message;
    }

    public function buildRuntimeErrorMessage(Throwable $exception): string
    {
        $details = $this->runtimeDiagnosticsFromException($exception);

        if (! empty($details['friendly_message'])) {
            return (string) $details['friendly_message'];
        }

        return 'DocuSign fallo al autenticarse o procesar la solicitud: '.$exception->getMessage();
    }

    public function runtimeDiagnosticsFromException(Throwable $exception): array
    {
        $payload = [];
        $errorCode = null;
        $errorDescription = null;
        $rawBody = null;
        $httpCode = method_exists($exception, 'getCode') ? $exception->getCode() : null;

        if ($exception instanceof ApiException) {
            $rawBody = $exception->getResponseBody();

            if (is_object($rawBody) || is_array($rawBody)) {
                $payload = json_decode(json_encode($rawBody), true) ?: [];
            } elseif (is_string($rawBody) && trim($rawBody) !== '') {
                $decoded = json_decode($rawBody, true);
                $payload = is_array($decoded) ? $decoded : ['raw' => $rawBody];
            }
        }

        $errorCode = data_get($payload, 'error')
            ?? data_get($payload, 'errorCode')
            ?? data_get($payload, 'code');
        $errorDescription = data_get($payload, 'error_description')
            ?? data_get($payload, 'message')
            ?? data_get($payload, 'raw');

        $friendlyMessage = match ((string) $errorCode) {
            'consent_required' => 'DocuSign rechazo el JWT porque falta otorgar consentimiento a la integracion.',
            'invalid_grant' => 'DocuSign rechazo el JWT por grant invalido. Revisa user ID, integration key y llave privada.',
            'user_not_found' => 'DocuSign no encontro el usuario configurado en DOCUSIGN_USER_ID.',
            'unauthorized_client' => 'La integracion de DocuSign no esta autorizada para usar este flujo JWT.',
            default => null,
        };

        return [
            'http_code' => $httpCode,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'error_code' => $errorCode,
            'error_description' => $errorDescription,
            'friendly_message' => $friendlyMessage,
            'response_payload' => $payload,
        ];
    }

    public function crearEnvelopeParaFirmaEmbebida(
        string $pdfAbsolutePath,
        string $signerName,
        string $signerEmail,
        string $clientUserId
    ): string {
        $apiClient = $this->getApiClient();
        $envelopesApi = new EnvelopesApi($apiClient);

        $document = new Document([
            'document_base64' => base64_encode((string) file_get_contents($pdfAbsolutePath)),
            'name' => 'Contrato de Servicio Aereo',
            'file_extension' => 'pdf',
            'document_id' => '1',
        ]);

        $signHere = new SignHere([
            'document_id' => '1',
            'recipient_id' => '1',
            'anchor_string' => '/sig_cliente/',
            'anchor_units' => 'pixels',
            'anchor_x_offset' => '0',
            'anchor_y_offset' => '0',
        ]);

        $signer = new Signer([
            'email' => $signerEmail,
            'name' => $signerName,
            'recipient_id' => '1',
            'routing_order' => '1',
            'client_user_id' => $clientUserId,
            'tabs' => new Tabs([
                'sign_here_tabs' => [$signHere],
            ]),
        ]);

        $envelope = new EnvelopeDefinition([
            'email_subject' => 'Firma de contrato de servicio aereo',
            'documents' => [$document],
            'recipients' => new Recipients([
                'signers' => [$signer],
            ]),
            'status' => 'sent',
        ]);

        $result = $envelopesApi->createEnvelope($this->accountId(), $envelope);

        return (string) $result->getEnvelopeId();
    }

    public function crearRecipientView(
        string $envelopeId,
        string $signerName,
        string $signerEmail,
        string $clientUserId,
        string $returnUrl
    ): string {
        $apiClient = $this->getApiClient();
        $envelopesApi = new EnvelopesApi($apiClient);

        $viewRequest = new RecipientViewRequest([
            'return_url' => $returnUrl,
            'authentication_method' => 'none',
            'email' => $signerEmail,
            'user_name' => $signerName,
            'client_user_id' => $clientUserId,
            'recipient_id' => '1',
        ]);

        $result = $envelopesApi->createRecipientView($this->accountId(), $envelopeId, $viewRequest);

        return (string) $result->getUrl();
    }

    public function obtenerEstadoEnvelope(string $envelopeId): string
    {
        $apiClient = $this->getApiClient();
        $envelopesApi = new EnvelopesApi($apiClient);
        $envelope = $envelopesApi->getEnvelope($this->accountId(), $envelopeId);

        return Str::lower((string) $envelope->getStatus());
    }

    public function descargarPdfCombinado(string $envelopeId): string
    {
        $apiClient = $this->getApiClient();
        $envelopesApi = new EnvelopesApi($apiClient);
        $document = $envelopesApi->getDocument($this->accountId(), 'combined', $envelopeId);

        if (is_string($document)) {
            return $document;
        }

        if (is_resource($document)) {
            return (string) stream_get_contents($document);
        }

        if (is_object($document) && method_exists($document, 'getContents')) {
            return (string) $document->getContents();
        }

        if (is_object($document) && method_exists($document, 'getPathname')) {
            return (string) file_get_contents($document->getPathname());
        }

        throw new RuntimeException('No fue posible descargar el PDF firmado desde DocuSign.');
    }

    public function construirReturnUrl(int $contractId, ?string $returnPath = null): string
    {
        $frontendUrl = rtrim((string) config('services.docusign.frontend_url'), '/');
        $path = $returnPath ?: (string) config('services.docusign.return_path', '/cliente/contrato/');
        $path = '/'.ltrim($path, '/');

        return $frontendUrl.$path.'?contract_id='.$contractId;
    }

    private function getApiClient(): ApiClient
    {
        if ($this->apiClient instanceof ApiClient) {
            return $this->apiClient;
        }

        if (! class_exists(ApiClient::class)) {
            throw new RuntimeException('La libreria de DocuSign no esta instalada. Ejecuta composer install.');
        }

        if (! $this->estaConfigurado()) {
            throw new RuntimeException($this->buildConfigurationErrorMessage());
        }

        $config = new Configuration();
        $config->setHost((string) config('services.docusign.base_path'));

        $apiClient = new ApiClient($config);
        $apiClient->getOAuth()->setOAuthBasePath((string) config('services.docusign.oauth_base_path'));

        $privateKey = $this->resolvePrivateKeyContents();

        try {
            $response = $apiClient->requestJWTUserToken(
                (string) config('services.docusign.integration_key'),
                (string) config('services.docusign.user_id'),
                $privateKey,
                ['signature', 'impersonation'],
                3600
            );
        } catch (Throwable $exception) {
            throw new RuntimeException($this->buildRuntimeErrorMessage($exception), (int) $exception->getCode(), $exception);
        }

        $accessToken = $response[0]->getAccessToken();
        $config->addDefaultHeader('Authorization', 'Bearer '.$accessToken);

        $this->apiClient = $apiClient;

        return $this->apiClient;
    }

    private function accountId(): string
    {
        return (string) config('services.docusign.account_id');
    }

    private function resolvePrivateKeyPath(): string
    {
        $path = (string) config('services.docusign.private_key_path');

        if ($path === '') {
            return '';
        }

        return Str::startsWith($path, ['/']) ? $path : base_path($path);
    }

    private function hasPrivateKey(): bool
    {
        return $this->resolvePrivateKeyContents(throwIfMissing: false) !== null;
    }

    private function privateKeySource(): string
    {
        $inlineKey = $this->sanitizeInlinePrivateKey((string) config('services.docusign.private_key', ''));
        $path = $this->resolvePrivateKeyPath();
        $fileContents = $path !== '' && is_file($path) ? (string) file_get_contents($path) : null;
        $fileIsValid = is_string($fileContents) && $this->validatePrivateKey($this->normalizePrivateKeyContents($fileContents)) === null;

        if ($inlineKey !== '') {
            $normalizedInlineKey = $this->normalizePrivateKeyContents(
                str_replace(["\\n", "\\r"], ["\n", "\r"], $inlineKey)
            );

            if ($this->validatePrivateKey($normalizedInlineKey) === null) {
                return 'env';
            }

            if ($fileIsValid) {
                return 'file_fallback';
            }

            return 'env';
        }

        if ($fileIsValid) {
            return 'file';
        }

        return 'missing';
    }

    private function resolvePrivateKeyContents(bool $throwIfMissing = true): ?string
    {
        $inlineKey = $this->sanitizeInlinePrivateKey((string) config('services.docusign.private_key', ''));

        if ($inlineKey !== '') {
            $normalizedInlineKey = $this->normalizePrivateKeyContents(
                str_replace(["\\n", "\\r"], ["\n", "\r"], $inlineKey)
            );

            if ($this->validatePrivateKey($normalizedInlineKey) === null) {
                return $normalizedInlineKey;
            }
        }

        $path = $this->resolvePrivateKeyPath();

        if ($path !== '' && is_file($path)) {
            $fileKey = $this->normalizePrivateKeyContents((string) file_get_contents($path));

            if ($this->validatePrivateKey($fileKey) === null) {
                return $fileKey;
            }
        }

        if ($path === '' || ! is_file($path)) {
            if ($throwIfMissing) {
                throw new RuntimeException('No se encontro la llave privada de DocuSign.');
            }

            return null;
        }

        if (! $throwIfMissing) {
            return $inlineKey !== ''
                ? $this->normalizePrivateKeyContents(str_replace(["\\n", "\\r"], ["\n", "\r"], $inlineKey))
                : $this->normalizePrivateKeyContents((string) file_get_contents($path));
        }

        throw new RuntimeException('La llave privada de DocuSign es invalida tanto en DOCUSIGN_PRIVATE_KEY como en DOCUSIGN_PRIVATE_KEY_PATH.');
    }

    private function sanitizeInlinePrivateKey(string $privateKey): string
    {
        return trim(trim($privateKey), "\"'");
    }

    private function normalizePrivateKeyContents(string $privateKey): string
    {
        $privateKey = trim(str_replace("\r\n", "\n", $privateKey));

        if (! preg_match('/^(-----BEGIN [A-Z ]+-----)\n?(.*)\n?(-----END [A-Z ]+-----)$/s', $privateKey, $matches)) {
            return $privateKey;
        }

        $header = trim($matches[1]);
        $body = preg_replace('/\s+/', '', (string) $matches[2]) ?? '';
        $footer = trim($matches[3]);

        if ($body === '') {
            return $privateKey;
        }

        return $header."\n".rtrim(chunk_split($body, 64, "\n"))."\n".$footer;
    }

    private function validatePrivateKey(string $privateKey): ?string
    {
        while (openssl_error_string() !== false) {
            // Limpiar errores previos de OpenSSL para no mezclar diagnosticos.
        }

        $resource = openssl_pkey_get_private($privateKey);

        if ($resource !== false) {
            return null;
        }

        return openssl_error_string() ?: 'OpenSSL no pudo decodificar la llave privada.';
    }

    private function detectInvalidPrivateKeyError(): ?string
    {
        $inlineKey = $this->sanitizeInlinePrivateKey((string) config('services.docusign.private_key', ''));

        if ($inlineKey !== '') {
            return $this->validatePrivateKey(
                $this->normalizePrivateKeyContents(
                    str_replace(["\\n", "\\r"], ["\n", "\r"], $inlineKey)
                )
            );
        }

        $path = $this->resolvePrivateKeyPath();

        if ($path !== '' && is_file($path)) {
            return $this->validatePrivateKey(
                $this->normalizePrivateKeyContents((string) file_get_contents($path))
            );
        }

        return null;
    }
}
