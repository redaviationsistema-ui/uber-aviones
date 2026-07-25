<?php

namespace App\Servicios\Ocr;

use Illuminate\Support\Str;

class DocumentImageProcessor
{
    public function prepare(string $filePath): array
    {
        $startedAt = microtime(true);
        $image = $this->loadImage($filePath);

        if (! $image) {
            throw new \RuntimeException('No fue posible abrir la imagen del documento.');
        }

        try {
            $image = $this->autoOrient($filePath, $image);
            $bounds = $this->detectDocumentBounds($image);
            $cropped = $bounds['detected'] ? $this->cropImage($image, $bounds) : $image;
            $resized = $this->resizeImage($cropped, 2200);
            $enhanced = $this->enhanceImage($resized);
            $quality = $this->analyzeQuality($enhanced, $bounds['detected']);
            $temporaryPath = storage_path('app/ocr_processed_'.Str::uuid()->toString().'.jpg');

            imagejpeg($enhanced, $temporaryPath, 90);

            return [
                'path' => $temporaryPath,
                'quality' => $quality,
                'processing_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'hash' => sha1_file($temporaryPath) ?: sha1($temporaryPath),
            ];
        } finally {
            if (isset($cropped) && $cropped !== $image) {
                imagedestroy($cropped);
            }
            if (isset($resized) && $resized !== $image && (! isset($cropped) || $resized !== $cropped)) {
                imagedestroy($resized);
            }
            if (isset($enhanced) && $enhanced !== $image && (! isset($resized) || $enhanced !== $resized)) {
                imagedestroy($enhanced);
            }
            imagedestroy($image);
        }
    }

    private function loadImage(string $filePath): ?\GdImage
    {
        $type = @exif_imagetype($filePath);

        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($filePath),
            IMAGETYPE_PNG => @imagecreatefrompng($filePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null,
            default => null,
        };
    }

    private function autoOrient(string $filePath, \GdImage $image): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($filePath);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function detectDocumentBounds(\GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $minX = $width;
        $minY = $height;
        $maxX = 0;
        $maxY = 0;
        $hits = 0;

        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);

                if ((255 - $gray) > 24) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                    $hits++;
                }
            }
        }

        if (! $hits || $minX >= $maxX || $minY >= $maxY) {
            return [
                'detected' => false,
                'left' => 0,
                'top' => 0,
                'width' => $width,
                'height' => $height,
                'coverage' => 0,
            ];
        }

        $paddingX = (int) round(($maxX - $minX) * 0.04);
        $paddingY = (int) round(($maxY - $minY) * 0.04);
        $left = max(0, $minX - $paddingX);
        $top = max(0, $minY - $paddingY);
        $right = min($width, $maxX + $paddingX);
        $bottom = min($height, $maxY + $paddingY);
        $boundedWidth = max(1, $right - $left);
        $boundedHeight = max(1, $bottom - $top);

        return [
            'detected' => true,
            'left' => $left,
            'top' => $top,
            'width' => $boundedWidth,
            'height' => $boundedHeight,
            'coverage' => ($boundedWidth * $boundedHeight) / max(1, $width * $height),
        ];
    }

    private function cropImage(\GdImage $image, array $bounds): \GdImage
    {
        $cropped = imagecrop($image, [
            'x' => $bounds['left'],
            'y' => $bounds['top'],
            'width' => $bounds['width'],
            'height' => $bounds['height'],
        ]);

        return $cropped ?: $image;
    }

    private function resizeImage(\GdImage $image, int $targetWidth): \GdImage
    {
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);

        if ($sourceWidth <= $targetWidth) {
            return $image;
        }

        $scale = $targetWidth / max(1, $sourceWidth);
        $canvas = imagecreatetruecolor($targetWidth, (int) round($sourceHeight * $scale));
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $sourceWidth, $sourceHeight);

        return $canvas;
    }

    private function enhanceImage(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        imagefilter($canvas, IMG_FILTER_GRAYSCALE);
        imagefilter($canvas, IMG_FILTER_CONTRAST, -18);
        imagefilter($canvas, IMG_FILTER_BRIGHTNESS, 6);
        imagefilter($canvas, IMG_FILTER_SMOOTH, -4);

        $matrix = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1],
        ];
        imageconvolution($canvas, $matrix, 8, 0);

        return $canvas;
    }

    private function analyzeQuality(\GdImage $image, bool $documentDetected): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $brightness = 0.0;
        $glarePixels = 0;
        $sampledPixels = 0;
        $blurAccumulator = 0.0;
        $blurSamples = 0;

        for ($y = 0; $y < $height - 1; $y += 3) {
            for ($x = 0; $x < $width - 1; $x += 3) {
                $current = $this->grayAt($image, $x, $y);
                $right = $this->grayAt($image, $x + 1, $y);
                $bottom = $this->grayAt($image, $x, $y + 1);

                $brightness += $current;
                if ($current >= 245) {
                    $glarePixels++;
                }

                $sampledPixels++;
                $blurAccumulator += abs($current - $right) + abs($current - $bottom);
                $blurSamples += 2;
            }
        }

        return [
            'blur' => $blurSamples ? round($blurAccumulator / $blurSamples, 2) : 0,
            'brightness' => $sampledPixels ? round($brightness / $sampledPixels, 2) : 0,
            'glare' => $sampledPixels ? round(($glarePixels / $sampledPixels) * 100, 2) : 0,
            'documentDetected' => $documentDetected,
            'cropped' => $documentDetected,
        ];
    }

    private function grayAt(\GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
    }
}
