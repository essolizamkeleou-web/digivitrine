<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Turns an uploaded image into a clean WebP file.
 *
 * The real type is read from the content (never from the name or the extension sent by the client),
 * the picture is decoded and re-encoded, so that what is stored is a genuine image without metadata
 * or hidden payload. Large pictures are shrunk. Accents are written as \u{..} escapes: ASCII source.
 */
final class ImageNormalizer
{
    public const MAX_SIDE = 1600;

    public const MAX_PIXELS = 16000000;

    public const QUALITY = 80;

    /**
     * @return array{data: string, width: int, height: int}
     */
    public function normalize(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $binary = $path === false ? false : @file_get_contents($path);

        if ($binary === false || $binary === '') {
            throw $this->rejected('Le fichier est vide ou illisible.');
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw $this->rejected("Le fichier n'est pas une image JPEG, PNG ou WebP valide.");
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw $this->rejected("L'image est trop grande (16 m\u{E9}gapixels maximum) : r\u{E9}duisez-la avant l'envoi.");
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw $this->rejected("L'image est corrompue et ne peut pas \u{EA}tre lue.");
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->upright($image, $path);
        }

        $image = $this->shrink($image);

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $written = imagewebp($image, null, self::QUALITY);
        $data = (string) ob_get_clean();

        if (! $written || $data === '') {
            throw $this->rejected("L'image n'a pas pu \u{EA}tre convertie.");
        }

        return ['data' => $data, 'width' => imagesx($image), 'height' => imagesy($image)];
    }

    /**
     * Applies the EXIF orientation of phone pictures (needs the exif extension, otherwise unchanged).
     */
    private function upright(GdImage $image, string|false $path): GdImage
    {
        if ($path === false || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);

        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }

    private function shrink(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::MAX_SIDE) {
            return $image;
        }

        $ratio = self::MAX_SIDE / $longest;
        $scaled = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), IMG_BICUBIC);

        return $scaled === false ? $image : $scaled;
    }

    private function rejected(string $message): ValidationException
    {
        return ValidationException::withMessages(['photo' => [$message]]);
    }
}
