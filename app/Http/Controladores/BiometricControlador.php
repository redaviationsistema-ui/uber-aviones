<?php

namespace App\Http\Controladores;

use App\Modelos\IdentityVerification;
use App\Modelos\Usuario;
use Aws\Exception\AwsException;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class BiometricControlador extends ControladorBase
{
    public function showStoredSelfie(Request $request, Usuario $user): Response
    {
        $path = $user->resolvedBiometricSelfiePath();
        $disk = $user->resolvedBiometricSelfieDisk();

        abort_unless($path, 404);
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response(
            $path,
            basename($path),
            ['Content-Disposition' => 'inline; filename="'.basename($path).'"']
        );
    }

    public function showStoredIdentityDocument(Request $request, Usuario $user, string $side): Response
    {
        $profile = $user->profile;
        abort_unless($profile, 404);

        $path = $side === 'back' ? $profile->ine_back_path : $profile->ine_front_path;

        abort_unless($path, 404);
        abort_unless(Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->response(
            $path,
            basename($path),
            ['Content-Disposition' => 'inline; filename="'.basename($path).'"']
        );
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

        $imagePath = $file->store('biometrics/selfies', 'private');

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
            $verification = $this->storeVerification(
                $request,
                imagePath: $imagePath,
                requestId: $requestId,
                payload: [
                    'identity_verified' => false,
                    'status' => 'rejected',
                    'face_occluded' => false,
                ],
            );

            $this->syncUserBiometricState($request, $verification, [
                'message' => 'No se detecto ningun rostro.',
                'face_detected' => false,
                'biometric_selfie_path' => $imagePath,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se detecto ningun rostro.',
                'identityVerified' => false,
                'faceDetected' => false,
                'facesCount' => 0,
                'biometricImageSaved' => true,
                'imagePath' => $imagePath,
            ], 422);
        }

        if (count($faces) > 1) {
            $verification = $this->storeVerification(
                $request,
                imagePath: $imagePath,
                requestId: $requestId,
                payload: [
                    'identity_verified' => false,
                    'status' => 'rejected',
                    'face_occluded' => false,
                ],
            );

            $this->syncUserBiometricState($request, $verification, [
                'message' => 'Se detecto mas de un rostro. Solo debe aparecer una persona.',
                'face_detected' => true,
                'biometric_selfie_path' => $imagePath,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Se detecto mas de un rostro. Solo debe aparecer una persona.',
                'identityVerified' => false,
                'faceDetected' => true,
                'facesCount' => count($faces),
                'biometricImageSaved' => true,
                'imagePath' => $imagePath,
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

        $verification = $this->storeVerification(
            $request,
            imagePath: $imagePath,
            requestId: $requestId,
            payload: [
                'identity_verified' => $approved,
                'status' => $approved ? 'approved' : 'rejected',
                'face_confidence' => $confidence,
                'brightness' => $brightness,
                'sharpness' => $sharpness,
                'yaw' => $yaw,
                'pitch' => $pitch,
                'roll' => $roll,
                'face_occluded' => $occluded,
            ],
        );

        $this->syncUserBiometricState($request, $verification, [
            'message' => $approved
                ? 'Rostro validado correctamente.'
                : 'Rostro detectado, pero no cumple la calidad requerida.',
            'face_detected' => true,
            'face_match_score' => $confidence,
            'image_storage_score' => $sharpness,
            'biometric_selfie_path' => $imagePath,
        ]);

        return response()->json([
            'success' => true,
            'message' => $approved
                ? 'Rostro validado correctamente.'
                : 'Rostro detectado, pero no cumple la calidad requerida.',
            'identityVerified' => $approved,
            'identityVerificationStatus' => $approved ? 'approved' : 'rejected',
            'biometricProvider' => 'aws_rekognition',
            'biometricTemplateType' => 'selfie-photo',
            'biometricImageSaved' => true,
            'imagePath' => $imagePath,
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
            'verificationId' => $verification->id,
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

    private function storeVerification(
        Request $request,
        string $imagePath,
        ?string $requestId,
        array $payload,
    ): IdentityVerification {
        return IdentityVerification::create($payload + [
            'user_id' => $request->user()?->id,
            'provider' => 'aws_rekognition',
            'template_type' => 'selfie-photo',
            'image_path' => $imagePath,
            'aws_request_id' => $requestId,
        ]);
    }

    private function syncUserBiometricState(Request $request, IdentityVerification $verification, array $data): void
    {
        $user = $request->user();

        if (! $user) {
            return;
        }

        $user->forceFill([
            'identity_verification_status' => $verification->status,
            'identity_verification_message' => $data['message'],
            'identity_verified' => $verification->identity_verified,
            'face_detected' => $data['face_detected'] ?? false,
            'face_match_score' => $data['face_match_score'] ?? $verification->face_confidence,
            'liveness_score' => null,
            'image_storage_score' => $data['image_storage_score'] ?? null,
            'biometric_image_saved' => Storage::disk('private')->exists($verification->image_path),
            'biometric_captured_at' => now(),
            'biometric_provider' => $verification->provider,
            'biometric_template_type' => $verification->template_type,
            'biometric_selfie_path' => $data['biometric_selfie_path'] ?? $verification->image_path,
            'biometric_selfie_disk' => 'private',
            'biometric_selfie_uploaded_at' => now(),
        ])->save();
    }
}
