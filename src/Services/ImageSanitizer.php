<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

final class ImageSanitizer
{
    private const MAX_PIXELS = 40_000_000;

    /** @return array{bytes: string, mime_type: string, extension: string} */
    public function sanitize(string $bytes, array $allowedMimeTypes): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('Image uploads require the PHP GD extension.');
        }

        $info = @getimagesizefromstring($bytes);
        $mimeType = is_array($info) ? ($info['mime'] ?? null) : null;
        $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;
        if (! is_string($mimeType) || ! in_array($mimeType, $allowedMimeTypes, true)) {
            throw new \InvalidArgumentException('The uploaded file is not an allowed image type.');
        }
        if ($width < 1 || $height < 1 || ($width * $height) > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('The uploaded image dimensions are not allowed.');
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new \InvalidArgumentException('The uploaded image could not be decoded safely.');
        }

        try {
            ob_start();
            $extension = match ($mimeType) {
                'image/jpeg' => tap('jpg', fn () => imagejpeg($image, null, 88)),
                'image/png' => tap('png', function () use ($image): void {
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    imagepng($image, null, 6);
                }),
                'image/webp' => tap('webp', fn () => imagewebp($image, null, 85)),
                default => throw new \InvalidArgumentException('The uploaded image type is not supported.'),
            };
            $sanitized = ob_get_clean();
        } finally {
            imagedestroy($image);
            if (ob_get_level() > 0 && ! isset($sanitized)) {
                ob_end_clean();
            }
        }

        if (! is_string($sanitized) || $sanitized === '') {
            throw new \RuntimeException('The uploaded image could not be sanitized.');
        }

        return ['bytes' => $sanitized, 'mime_type' => $mimeType, 'extension' => $extension];
    }
}
