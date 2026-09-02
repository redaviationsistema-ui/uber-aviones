<?php

namespace App\Http\Controladores;

use App\Modelos\Usuario;
use App\Servicios\Identidad\IdentityStorageServicio;
use Aws\Exception\AwsException;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BiometricControlador extends ControladorBase
{
    public function showStoredSelfie(Request $request, Usuario $user): StreamedResponse
    {
        $path = $user->resolvedBiometricSelfiePath();
        $disk = $user->resolvedBiometricSelfieDisk();

        abort_unless($path !== '', 404);
        if (! Storage::disk($disk)->exists($path)) {
            Log::warning('[BIOMETRIC_STORAGE_MISSING]', ['user_id' => $user->id, 'disk' => $disk, 'path' => $path]);
            abort(404);
        }

        return Storage::disk($disk)->response(
            $path,
            basename($path),
            ['Content-Disposition' => $request->boolean('download') ? 'attachment' : 'inline; filename="'.basename($path).'"']
        );
    }

    public function showStoredIdentityDocument(Request $request, Usuario $user, string $side): StreamedResponse
    {
        $profile = $user->profile;
        abort_unless($profile, 404);

        $rawPath = $side === 'back' ? $profile->ine_back_path : $profile->ine_front_path;
        $path = $this->normalizePrivateStoragePath((string) $rawPath);

        $storage = app(IdentityStorageServicio::class);
        $disk = $storage->diskName();
        abort_unless($path, 404);
        if (! $storage->disk()->exists($path)) {
            Log::warning('[IDENTITY_STORAGE_MISSING]', ['user_id' => $user->id, 'side' => $side, 'disk' => $disk, 'path' => $path]);
            abort(404);
        }

        return $storage->disk()->response(
            $path,
            basename($path),
            ['Content-Disposition' => $request->boolean('download') ? 'attachment' : 'inline; filename="'.basename($path).'"']
        );
    }

    private function normalizePrivateStoragePath(string $path): string
    {
        $normalized = trim($path);

        if ($normalized === '') {
            return '';
        }

        if (filter_var($normalized, FILTER_VALIDATE_URL)) {
            $urlPath = parse_url($normalized, PHP_URL_PATH);
            $normalized = is_string($urlPath) ? $urlPath : $normalized;
        }

        $normalized = ltrim($normalized, '/');
        $normalized = preg_replace('#^storage/#', '', $normalized) ?? $normalized;
        $normalized = preg_replace('#^private/#', '', $normalized) ?? $normalized;

        return $normalized;
    }

    public function detectFace(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selfie' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $file = $data['selfie'];
        $imageBytes = file_get_contents($file->getRealPath());

        if ($imageBytes === false) {
            return response()->json([
                'success' => false,
                'message' => 'No fue posible leer la imagen enviada.',
            ], 422);
        }

        try {
            $result = $this->rekognition()->detectFaces([
                'Image' => [
                    'Bytes' => $imageBytes,
                ],
                'Attributes' => ['ALL'],
            ]);
        } catch (AwsException $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'No fue posible validar el rostro con AWS Rekognition.',
                'errorCode' => $exception->getAwsErrorCode(),
            ], 502);
        }

        $faces = $result['FaceDetails'] ?? [];
        $requestId = $result['@metadata']['headers']['x-amzn-requestid']
            ?? $result['@metadata']['headers']['x-amz-request-id']
            ?? null;

        if (count($faces) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se detecto ningun rostro.',
                'identityVerified' => false,
                'faceDetected' => false,
                'facesCount' => 0,
                'identityVerificationStatus' => 'rejected',
                'biometricProvider' => 'aws_rekognition',
                'biometricTemplateType' => 'selfie-photo',
            ], 422);
        }

        if (count($faces) > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Se detecto mas de un rostro. Solo debe aparecer una persona.',
                'identityVerified' => false,
                'faceDetected' => true,
                'facesCount' => count($faces),
                'identityVerificationStatus' => 'rejected',
                'biometricProvider' => 'aws_rekognition',
                'biometricTemplateType' => 'selfie-photo',
            ], 422);
        }

        $face = $faces[0];
        $confidence = round((float) ($face['Confidence'] ?? 0), 2);
        $brightness = round((float) ($face['Quality']['Brightness'] ?? 0), 2);
        $sharpness = round((float) ($face['Quality']['Sharpness'] ?? 0), 2);
        $yaw = round(abs((float) ($face['Pose']['Yaw'] ?? 0)), 2);
        $pitch = round(abs((float) ($face['Pose']['Pitch'] ?? 0)), 2);
        $roll = round(abs((float) ($face['Pose']['Roll'] ?? 0)), 2);
        $occluded = (bool) ($face['FaceOccluded']['Value'] ?? false);

        $approved = $confidence >= 95
            && $brightness >= 40
            && $sharpness >= 40
            && $yaw <= 25
            && $pitch <= 25
            && $roll <= 25
            && $occluded === false;

        return response()->json([
            'success' => true,
            'message' => $approved
                ? 'Rostro validado correctamente.'
                : 'Rostro detectado, pero no cumple la calidad requerida.',
            'identityVerified' => $approved,
            'identityVerificationStatus' => $approved ? 'approved' : 'rejected',
            'biometricProvider' => 'aws_rekognition',
            'biometricTemplateType' => 'selfie-photo',
            'faceDetected' => true,
            'facesCount' => 1,
            'faceConfidence' => $confidence,
            'quality' => [
                'brightness' => $brightness,
                'sharpness' => $sharpness,
            ],
            'pose' => [
                'yaw' => $yaw,
                'pitch' => $pitch,
                'roll' => $roll,
            ],
            'faceOccluded' => $occluded,
            'awsRequestId' => $requestId,
        ]);
    }

    private function rekognition(): RekognitionClient
    {
        return new RekognitionClient([
            'region' => env('AWS_REKOGNITION_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'version' => 'latest',
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);
    }
}
