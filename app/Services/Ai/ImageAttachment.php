<?php

namespace App\Services\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Shrinks a chat attachment before it is stored or sent upstream.
 *
 * This is the main free-tier lever on the image path. Gemini bills an image by tiling it, so a
 * 12-megapixel phone photo costs several times what the same receipt costs at 1024px — and it is
 * no more readable, because the model is looking at the same printed text either way. Re-encoding
 * to JPEG also cuts what lands in S3 by roughly an order of magnitude, which matters when the
 * bucket is on a free allowance measured in PUTs and gigabytes.
 *
 * Everything is best-effort: a decode failure, a missing GD codec or an image too large to hold
 * in memory falls back to the original bytes. The upload cap already bounds the damage, so a
 * chat must never fail because a photo could not be resized.
 */
class ImageAttachment
{
    /**
     * Backstop against a decode bomb, not a memory budget. A byte-based upload cap does not bound
     * pixels: a nearly flat 48 MP image compresses to about 770 KB and sails straight through a
     * 1536 KB limit.
     *
     * Measured on this codebase rather than assumed — a 12 MP phone photo and a 48 MP flat image
     * both peak around 35 MB of PHP memory and resize successfully at a 64M limit, so GD's JPEG
     * decode is nothing like the width x height x 4 the arithmetic suggests. 40 MP is therefore
     * set well above anything a camera produces, purely so a hostile file cannot pick the number.
     */
    private const MAX_SOURCE_MEGAPIXELS = 40;

    /**
     * @return array{bytes: string, mime: string, extension: string}
     */
    public function normalise(UploadedFile $file): array
    {
        $original = [
            'bytes' => (string) file_get_contents($file->getRealPath()),
            'mime' => $file->getMimeType() ?: 'image/jpeg',
            'extension' => $file->guessExtension() ?: 'jpg',
        ];

        if (! function_exists('imagecreatefromstring')) {
            return $original;
        }

        $size = @getimagesize($file->getRealPath());

        if ($size === false || ($size[0] * $size[1]) > self::MAX_SOURCE_MEGAPIXELS * 1_000_000) {
            return $original;
        }

        try {
            $shrunk = $this->shrink($original['bytes'], $file->getRealPath());
        } catch (\Throwable $e) {
            Log::warning('Chat attachment could not be resized', ['error' => $e->getMessage()]);

            return $original;
        }

        if ($shrunk === null) {
            return $original;
        }

        // Byte size only gets a vote when the dimensions did not change: a small PNG can
        // re-encode larger than it started, and there is nothing to gain by keeping the JPEG.
        // Once pixels have actually been dropped the shrunk copy always wins, because image
        // tokens are billed on dimensions, not on file size — a flat 4000px screenshot
        // compresses tiny and would still be tiled into a small fortune.
        if (! $shrunk['scaled'] && strlen($shrunk['bytes']) >= strlen($original['bytes'])) {
            return $original;
        }

        return ['bytes' => $shrunk['bytes'], 'mime' => 'image/jpeg', 'extension' => 'jpg'];
    }

    /**
     * @return array{bytes: string, scaled: bool}|null
     */
    private function shrink(string $bytes, string $path): ?array
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            // Most likely a WebP on a GD build compiled without --with-webp.
            return null;
        }

        // No imagedestroy() anywhere below: it has been a no-op since PHP 8.0 (GdImage is
        // refcounted like any other object) and is deprecated outright in 8.5, which the local
        // CLI runs. Calling it would only add notices.
        $source = $this->applyExifOrientation($source, $path);

        $width = imagesx($source);
        $height = imagesy($source);
        $maxEdge = max(1, (int) config('services.gemini.image.max_edge', 1024));

        $scale = min(1, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        // JPEG has no alpha, so a transparent PNG would otherwise composite onto black.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $quality = max(1, min(100, (int) config('services.gemini.image.jpeg_quality', 82)));

        ob_start();
        imagejpeg($canvas, null, $quality);
        $out = (string) ob_get_clean();

        return $out === '' ? null : ['bytes' => $out, 'scaled' => $scale < 1];
    }

    /**
     * Re-encoding drops the EXIF block, so a phone photo's rotation has to be baked into the
     * pixels first — otherwise normalising would turn an upright receipt sideways and make the
     * model's reading of it worse than before we touched it. Only the three rotation
     * orientations are handled; the mirrored ones are not produced by cameras.
     */
    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $angle = match ((int) ($exif['Orientation'] ?? 0)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = @imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }
}
