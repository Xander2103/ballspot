<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Builds a downscaled, re-encoded DISPLAY copy of an uploaded image next to
 * the original — the original upload is never modified or deleted here.
 *
 * Why: admins upload phone photos (up to 5 MB, ~4000 px) and the app showed
 * them as-is on a ~400 pt wide card, so every Daily/Pack/Tournament/Result
 * screen downloaded and decoded the full original.
 *
 * Geometry guarantees (the ball position and every guess are 0..1 ratios of
 * the displayed image, see ScoringService):
 *  - uniform scale only — never cropped, padded or stretched; the aspect ratio
 *    changes by at most the rounding of one pixel;
 *  - the JPEG EXIF orientation is applied (and the tag dropped), so the copy
 *    looks exactly like the original does in a browser, the admin ball picker
 *    and both mobile platforms — all of which honour that tag;
 *  - never upscaled.
 *
 * Output is WebP (smaller at equal quality; decoded natively by iOS 14+,
 * Android, expo-image and every current browser) with a JPEG fallback when
 * this PHP's GD has no WebP encoder. All metadata (EXIF/GPS) is stripped.
 *
 * Variant file names are derived from the source file name, which Laravel's
 * store() makes random and unique per upload — so a replaced photo always gets
 * a new URL and no client cache can ever show the old one.
 */
class ImageVariantService
{
    /** Bump to regenerate every variant with new settings (new file names). */
    public const VERSION = 1;

    public const WEBP_QUALITY = 82;
    public const JPEG_QUALITY = 84;

    /** Refuse to decode anything larger than this (memory guard, ~200 MB RGBA). */
    public const MAX_SOURCE_PIXELS = 50_000_000;

    /**
     * A variant that is not resized and saves less than this fraction is not
     * worth a second file: the original is served instead.
     */
    public const MIN_SAVING = 0.15;

    public function __construct(private readonly string $disk = 'public')
    {
    }

    /** What this PHP can do — shown by ballspot:images-audit. */
    public static function capabilities(): array
    {
        return [
            'gd'   => function_exists('imagecreatefromstring') && function_exists('imagecopyresampled'),
            'webp' => function_exists('imagewebp'),
            'jpeg' => function_exists('imagejpeg'),
            'exif_ext' => function_exists('exif_read_data'),
        ];
    }

    /**
     * Create the display variant of $sourcePath (relative to the disk).
     *
     * Returns metadata to store on the model:
     *  - path = the variant (relative path) or null when the original is
     *    already optimal (then width/height are the original's);
     *  - null when nothing could be done (file missing, GD missing, not an
     *    image, too large to decode) — the caller keeps serving the original
     *    and a later ballspot:images-optimize run can retry.
     *
     * @return array{v:int, source:string, path:?string, width:int, height:int, bytes:int, source_bytes:int, format:?string, max_edge:int}|null
     */
    public function generate(string $sourcePath, string $variantDir, int $maxEdge): ?array
    {
        $storage = Storage::disk($this->disk);
        if ($sourcePath === '' || ! $storage->exists($sourcePath)) {
            return null;
        }

        $caps = self::capabilities();
        if (! $caps['gd'] || (! $caps['webp'] && ! $caps['jpeg'])) {
            return null;
        }

        $bytes = $storage->get($sourcePath);
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ($info[0] ?? 0) <= 0 || ($info[1] ?? 0) <= 0) {
            return null; // SVG and non-images: nothing to optimise
        }
        [$srcW, $srcH] = [(int) $info[0], (int) $info[1]];
        $mime = (string) ($info['mime'] ?? '');
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }
        if ($srcW * $srcH > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        $orientation = $mime === 'image/jpeg' ? self::jpegOrientation($bytes) : 1;
        $swapsAxes = $orientation >= 5 && $orientation <= 8;
        // Displayed (oriented) size — what the admin, browser and app see.
        [$dispW, $dispH] = $swapsAxes ? [$srcH, $srcW] : [$srcW, $srcH];

        $scale = min(1.0, $maxEdge / max($dispW, $dispH));
        $outW = max(1, (int) round($dispW * $scale));
        $outH = max(1, (int) round($dispH * $scale));
        $resized = $scale < 1.0;

        $this->ensureMemoryFor($srcW, $srcH, $outW, $outH);

        $src = @imagecreatefromstring($bytes);
        unset($bytes);
        if ($src === false) {
            return null;
        }
        $hasAlpha = $mime !== 'image/jpeg';

        // Resize in the stored orientation first (cheap), rotate the small copy after.
        [$resW, $resH] = $swapsAxes ? [$outH, $outW] : [$outW, $outH];
        $dst = imagecreatetruecolor($resW, $resH);
        if ($hasAlpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $resW, $resH, $srcW, $srcH);
        imagedestroy($src);

        $dst = self::applyOrientation($dst, $orientation);

        $format = $caps['webp'] ? 'webp' : 'jpg';
        if ($format === 'jpg' && $hasAlpha) {
            $dst = self::flattenOnWhite($dst);
        }

        ob_start();
        $ok = $format === 'webp'
            ? imagewebp($dst, null, self::WEBP_QUALITY)
            : (imageinterlace($dst, true) || true) && imagejpeg($dst, null, self::JPEG_QUALITY);
        $encoded = ob_get_clean();
        imagedestroy($dst);
        if (! $ok || ! is_string($encoded) || $encoded === '') {
            return null;
        }

        $sourceBytes = (int) $storage->size($sourcePath);
        $meta = [
            'v'            => self::VERSION,
            'source'       => $sourcePath,
            'path'         => null,
            'width'        => $outW,
            'height'       => $outH,
            'bytes'        => $sourceBytes,
            'source_bytes' => $sourceBytes,
            'format'       => null,
            'max_edge'     => $maxEdge,
        ];

        $worthIt = $resized || $orientation !== 1 || strlen($encoded) <= $sourceBytes * (1 - self::MIN_SAVING);
        if (! $worthIt || strlen($encoded) >= $sourceBytes) {
            // The original is already as small as we can make it. Its own
            // (oriented) size is what clients render.
            return $orientation === 1 ? $meta : null;
        }

        $path = rtrim($variantDir, '/') . '/' . self::variantBaseName($sourcePath, $maxEdge) . '.' . $format;
        $storage->put($path, $encoded);

        return array_merge($meta, [
            'path'   => $path,
            'bytes'  => strlen($encoded),
            'format' => $format,
        ]);
    }

    /** Delete a variant file described by stored metadata (never the source). */
    public function deleteVariant(?array $meta): void
    {
        $path = is_array($meta) ? ($meta['path'] ?? null) : null;
        if (is_string($path) && $path !== '' && $path !== ($meta['source'] ?? null)) {
            Storage::disk($this->disk)->delete($path);
        }
    }

    public static function variantBaseName(string $sourcePath, int $maxEdge): string
    {
        return pathinfo($sourcePath, PATHINFO_FILENAME) . '-w' . $maxEdge . '-v' . self::VERSION;
    }

    /**
     * EXIF orientation (1..8) of a JPEG, read straight from the APP1 segment so
     * it works without the exif extension. 1 when absent or unreadable.
     */
    public static function jpegOrientation(string $bytes): int
    {
        $len = strlen($bytes);
        if ($len < 4 || $bytes[0] !== "\xFF" || $bytes[1] !== "\xD8") {
            return 1;
        }
        $pos = 2;
        while ($pos + 4 <= $len) {
            if ($bytes[$pos] !== "\xFF") {
                return 1;
            }
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xD9 || $marker === 0xDA) {
                return 1; // end of image / start of scan: no EXIF before the pixels
            }
            $segLen = unpack('n', substr($bytes, $pos + 2, 2))[1];
            if ($segLen < 2 || $pos + 2 + $segLen > $len) {
                return 1;
            }
            if ($marker === 0xE1 && substr($bytes, $pos + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($bytes, $pos + 10, $segLen - 8));
            }
            $pos += 2 + $segLen;
        }

        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long  = $order === 'II' ? 'V' : 'N';
        $ifd = unpack($long, substr($tiff, 4, 4))[1];
        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }
        $count = unpack($short, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                return 1;
            }
            if (unpack($short, substr($tiff, $entry, 2))[1] === 0x0112) {
                $value = unpack($short, substr($tiff, $entry + 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /** Rotate/flip so the pixels look the way an EXIF-aware viewer shows them. */
    private static function applyOrientation(\GdImage $img, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 2: imageflip($img, IMG_FLIP_HORIZONTAL); return $img;
            case 3: return self::rotate($img, 180);
            case 4: imageflip($img, IMG_FLIP_VERTICAL); return $img;
            case 5: $img = self::rotate($img, 270); imageflip($img, IMG_FLIP_HORIZONTAL); return $img;
            case 6: return self::rotate($img, 270);
            case 7: $img = self::rotate($img, 90); imageflip($img, IMG_FLIP_HORIZONTAL); return $img;
            case 8: return self::rotate($img, 90);
            default: return $img;
        }
    }

    /** imagerotate is counter-clockwise: 270 = 90° clockwise. */
    private static function rotate(\GdImage $img, int $degrees): \GdImage
    {
        $rotated = imagerotate($img, $degrees, 0);
        if ($rotated === false) {
            return $img;
        }
        imagedestroy($img);

        return $rotated;
    }

    private static function flattenOnWhite(\GdImage $img): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

        return $flat;
    }

    /**
     * A decoded 4032x3024 photo needs ~50 MB; php-fpm's default 128M is tight
     * once the framework is loaded. Raise the limit for this request only.
     */
    private function ensureMemoryFor(int $srcW, int $srcH, int $outW, int $outH): void
    {
        $needed = (int) (($srcW * $srcH + 2 * $outW * $outH) * 5 + 64 * 1024 * 1024);
        $limit = self::bytesFromIni((string) ini_get('memory_limit'));
        if ($limit > 0 && $limit < $needed) {
            @ini_set('memory_limit', (string) max($needed, 256 * 1024 * 1024));
        }
    }

    private static function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 ** 3,
            'm' => $num * 1024 ** 2,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
