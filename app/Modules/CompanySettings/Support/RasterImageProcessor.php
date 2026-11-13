<?php

namespace App\Modules\CompanySettings\Support;

use Illuminate\Validation\ValidationException;

/**
 * Проверка и повторное кодирование загруженных растровых изображений
 * (план §9.4): finfo + getimagesize + декодирование реального содержимого,
 * re-encode через GD (сбрасывает встроенные payload/exif). SVG не принимается.
 */
final class RasterImageProcessor
{
    public const MAX_BYTES_LOGO = 2 * 1024 * 1024;

    public const MIN_DIMENSION = 32;

    public const MAX_DIMENSION = 2048;

    public const PIXEL_BUDGET = 4_000_000;

    private const ALLOWED_MIME = [
        'image/png' => ['png', 'imagepng', IMAGETYPE_PNG],
        'image/jpeg' => ['jpg', 'imagejpeg', IMAGETYPE_JPEG],
        'image/webp' => ['webp', 'imagewebp', IMAGETYPE_WEBP],
    ];

    /**
     * @return array{binary: string, mime: string, extension: string, width: int, height: int}
     *
     * @throws ValidationException
     */
    public static function process(string $content, int $maxBytes): array
    {
        if (strlen($content) === 0 || strlen($content) > $maxBytes) {
            throw ValidationException::withMessages([
                'logo' => [__('validation.branding.size', ['max' => (int) ($maxBytes / 1024)])],
            ]);
        }

        $mime = self::detectMime($content);

        if ($mime === null) {
            throw ValidationException::withMessages([
                'logo' => [__('validation.branding.format')],
            ]);
        }

        $size = getimagesizefromstring($content);

        if ($size === false) {
            throw ValidationException::withMessages([
                'logo' => [__('validation.branding.corrupt')],
            ]);
        }

        [$width, $height] = $size;

        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            throw ValidationException::withMessages([
                'logo' => [__('validation.branding.too_small', ['min' => self::MIN_DIMENSION])],
            ]);
        }

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width * $height > self::PIXEL_BUDGET) {
            throw ValidationException::withMessages([
                'logo' => [__('validation.branding.too_large', ['max' => self::MAX_DIMENSION])],
            ]);
        }

        $decoded = imagecreatefromstring($content);

        if ($decoded === false) {
            throw ValidationException::withMessages([
                'logo' => [__('validation.branding.corrupt')],
            ]);
        }

        // Нормализуем в truecolor без alpha-канала: логотипы печатаются
        // на белом фоне документа.
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        if ($white === false) {
            throw ValidationException::withMessages([
                'logo' => [__('validation.branding.corrupt')],
            ]);
        }

        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $decoded, 0, 0, 0, 0, $width, $height);
        imagedestroy($decoded);

        [$extension, $encoder] = self::ALLOWED_MIME[$mime];
        $binary = self::encode($encoder, $canvas);
        imagedestroy($canvas);

        if ($binary === null || $binary === '') {
            throw ValidationException::withMessages([
                'logo' => [__('validation.branding.corrupt')],
            ]);
        }

        return [
            'binary' => $binary,
            'mime' => $mime,
            'extension' => $extension,
            'width' => $width,
            'height' => $height,
        ];
    }

    private static function detectMime(string $content): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($content);

        return is_string($detected) && array_key_exists($detected, self::ALLOWED_MIME)
            ? $detected
            : null;
    }

    private static function encode(string $encoder, \GdImage $image): ?string
    {
        ob_start();
        $ok = match ($encoder) {
            'imagepng' => imagepng($image, null, 6),
            'imagejpeg' => imagejpeg($image, null, 92),
            'imagewebp' => imagewebp($image, null, 92),
            default => false,
        };

        $binary = ob_get_clean();

        return $ok !== false && is_string($binary) && $binary !== '' ? $binary : null;
    }
}
