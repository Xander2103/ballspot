<?php

namespace App\Services\Clubs;

use App\Exceptions\ClubDomainException;
use App\Models\Club;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Private club media (plan §6.7, contracts §3.6).
 *
 *  - Photos and club challenge images go to the `club_private` disk
 *    (storage/app/private/clubs — never /storage) under random names:
 *      {club_id}/photos/{random40}.jpg
 *      {club_id}/challenges/hidden/{random40}.jpg
 *      {club_id}/challenges/original/{random40}.jpg
 *    and are served only through the temporary signed `clubs.media` route.
 *  - Every image is decoded and re-encoded with GD (JPEG q85), which drops
 *    EXIF/GPS and every other metadata segment (same approach as
 *    Api\AvatarController). The EXIF orientation is applied first so phone
 *    photos keep their rotation; the longest edge is capped at max_edge.
 *  - Logos are low-sensitivity and live on the PUBLIC disk under random
 *    names (D24): clubs/logos/{random40}.webp|jpg. Club pack covers too:
 *    packs/covers/{random40}.webp|jpg (re-encoded, so no EXIF/GPS).
 *
 * Unlike the avatar flow there is no raw-store fallback: a file GD cannot
 * decode is refused with `photo_invalid`, so metadata can never slip through.
 */
class ClubMediaService
{
    public const KIND_PHOTO              = 'photo';
    public const KIND_CHALLENGE_HIDDEN   = 'challenge_hidden';
    public const KIND_CHALLENGE_ORIGINAL = 'challenge_original';

    public const KIND_DIRECTORIES = [
        self::KIND_PHOTO              => 'photos',
        self::KIND_CHALLENGE_HIDDEN   => 'challenges/hidden',
        self::KIND_CHALLENGE_ORIGINAL => 'challenges/original',
    ];

    public const LOGO_DIRECTORY = 'clubs/logos';
    /** Same directory as BallPicker pack covers (served from /storage like them). */
    public const PACK_COVER_DIRECTORY = 'packs/covers';

    private const JPEG_QUALITY = 85;
    private const LOGO_MAX_EDGE = 1024;
    private const PACK_COVER_MAX_EDGE = 2048;
    /** Decompression-bomb guard: refuse before GD allocates the bitmap (~240 MB at 60 MP). */
    private const MAX_INPUT_PIXELS = 60_000_000;
    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function disk(): string
    {
        return (string) config('ballspot.clubs.media.disk', 'club_private');
    }

    /**
     * Re-encode and store a club image on the private disk.
     *
     * @return array{path: string, width: int, height: int, bytes: int, sha256: string, mime: string}
     *         sha256 is of the ORIGINAL upload (per-club duplicate detection).
     */
    public function storeImage(UploadedFile $file, Club $club, string $kind): array
    {
        $dir = self::KIND_DIRECTORIES[$kind] ?? throw new \InvalidArgumentException("Unknown club media kind [{$kind}].");

        $contents = $this->readUpload($file, (int) config('ballspot.clubs.media.max_kb', 15360));
        $image    = $this->decode($contents);
        $image    = $this->scaleDown($image, (int) config('ballspot.clubs.media.max_edge', 2560));

        $binary = $this->encodeJpeg($image);
        $width  = imagesx($image);
        $height = imagesy($image);

        $path = $club->id . '/' . $dir . '/' . Str::random(40) . '.jpg';
        Storage::disk($this->disk())->put($path, $binary);

        return [
            'path'   => $path,
            'width'  => $width,
            'height' => $height,
            'bytes'  => strlen($binary),
            'sha256' => hash('sha256', $contents),
            'mime'   => 'image/jpeg',
        ];
    }

    /** Re-encode and store a club logo on the PUBLIC disk; returns its path. */
    public function storeLogo(UploadedFile $file, Club $club): string
    {
        return $this->storePublicImage($file, self::LOGO_DIRECTORY, (int) config('ballspot.clubs.media.logo_max_kb', 2048), self::LOGO_MAX_EDGE);
    }

    /**
     * Re-encode and store a CLUB pack cover on the PUBLIC disk under a random
     * name (packs/covers/{random40}.webp|jpg) — EXIF/GPS dropped like every
     * other club image. BallPicker pack covers keep the plain upload.
     */
    public function storePackCover(UploadedFile $file, Club $club): string
    {
        return $this->storePublicImage($file, self::PACK_COVER_DIRECTORY, (int) config('ballspot.clubs.media.max_kb', 15360), self::PACK_COVER_MAX_EDGE);
    }

    /** GD re-encode (WebP with alpha, JPEG fallback) onto the public disk; returns the path. */
    private function storePublicImage(UploadedFile $file, string $directory, int $maxKb, int $maxEdge): string
    {
        $contents = $this->readUpload($file, $maxKb);
        $image    = $this->scaleDown($this->decode($contents), $maxEdge);

        $webp = function_exists('imagewebp');
        ob_start();
        if ($webp) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $ok = imagewebp($image, null, 90);
        } else {
            $image = $this->flatten($image);
            $ok    = imagejpeg($image, null, 90);
        }
        $binary = (string) ob_get_clean();

        if (!$ok || $binary === '') {
            throw new ClubDomainException(ClubDomainException::PHOTO_INVALID, 422);
        }

        $path = $directory . '/' . Str::random(40) . ($webp ? '.webp' : '.jpg');
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    /** Temporary signed URL of the `clubs.media` route (TTL from config). */
    public function signedUrl(string $path): string
    {
        $this->assertSafePath($path);

        return URL::temporarySignedRoute(
            'clubs.media',
            now()->addMinutes(max(1, (int) config('ballspot.clubs.media.signed_url_ttl_minutes', 60))),
            ['path' => $path],
        );
    }

    /**
     * Copy an already stored (re-encoded) private club file to a new random
     * path of another kind, e.g. a photo submission → a challenge original.
     * The source stays untouched; returns the new path.
     */
    public function copyImage(string $sourcePath, Club $club, string $kind): string
    {
        $this->assertSafePath($sourcePath);
        $dir = self::KIND_DIRECTORIES[$kind] ?? throw new \InvalidArgumentException("Unknown club media kind [{$kind}].");

        $disk = Storage::disk($this->disk());
        if (!str_starts_with($sourcePath, $club->id . '/') || !$disk->exists($sourcePath)) {
            throw new ClubDomainException(ClubDomainException::PHOTO_INVALID, 422);
        }

        $path = $club->id . '/' . $dir . '/' . Str::random(40) . '.jpg';
        if (!$disk->copy($sourcePath, $path)) {
            throw new ClubDomainException(ClubDomainException::PHOTO_INVALID, 422);
        }

        return $path;
    }

    /** Delete a private club file (no-op for unsafe paths). */
    public function delete(string $path): void
    {
        if (!self::isSafePath($path)) {
            return;
        }
        Storage::disk($this->disk())->delete($path);
    }

    /** Delete a stored logo — only ever inside clubs/logos/ on the public disk. */
    public function deleteLogo(?string $path): void
    {
        if ($path && self::isSafePath($path) && str_starts_with($path, self::LOGO_DIRECTORY . '/')) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Relative, no traversal, no absolute or Windows-style paths, no control
     * characters. The media route applies the same check.
     */
    public static function isSafePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 255
            && !str_contains($path, '..')
            && !str_contains($path, '\\')
            && !str_starts_with($path, '/')
            && !preg_match('/[\x00-\x1F\x7F]/', $path)
            && (bool) preg_match('#^[A-Za-z0-9/_\-.]+$#', $path);
    }

    // ------------------------------------------------------------------

    private function assertSafePath(string $path): void
    {
        if (!self::isSafePath($path)) {
            throw new \InvalidArgumentException('Unsafe club media path.');
        }
    }

    private function readUpload(UploadedFile $file, int $maxKb): string
    {
        if (!$file->isValid() || $file->getSize() === false || $file->getSize() > $maxKb * 1024) {
            throw new ClubDomainException(ClubDomainException::PHOTO_INVALID, 422);
        }

        $contents = @file_get_contents($file->getRealPath());
        if ($contents === false || $contents === '') {
            throw new ClubDomainException(ClubDomainException::PHOTO_INVALID, 422);
        }

        return $contents;
    }

    /** @return \GdImage */
    private function decode(string $contents)
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('GD is required for club media.');
        }

        $info = @getimagesizefromstring($contents);
        if ($info === false
            || !in_array($info[2] ?? null, self::ALLOWED_TYPES, true)
            || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1
            || $info[0] * $info[1] > self::MAX_INPUT_PIXELS) {
            throw new ClubDomainException(ClubDomainException::PHOTO_INVALID, 422);
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            throw new ClubDomainException(ClubDomainException::PHOTO_INVALID, 422);
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->applyExifOrientation($image, $contents);
        }

        return $image;
    }

    /** Rotate/flip per the EXIF Orientation tag, since re-encoding drops the tag. */
    private function applyExifOrientation($image, string $contents)
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($contents));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            2 => imageflip($image, IMG_FLIP_HORIZONTAL) ? $image : $image,
            3 => imagerotate($image, 180, 0),
            4 => imageflip($image, IMG_FLIP_VERTICAL) ? $image : $image,
            5 => imageflip($image, IMG_FLIP_HORIZONTAL) ? imagerotate($image, 90, 0) : $image,
            6 => imagerotate($image, -90, 0),
            7 => imageflip($image, IMG_FLIP_HORIZONTAL) ? imagerotate($image, -90, 0) : $image,
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated !== false ? $rotated : $image;
    }

    private function scaleDown($image, int $maxEdge)
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $longest = max($w, $h);
        if ($maxEdge < 1 || $longest <= $maxEdge) {
            return $image;
        }

        // imagecopyresampled works on every GD build (imagescale() with
        // IMG_BICUBIC silently returns false on some).
        $ratio  = $maxEdge / $longest;
        $nw     = max(1, (int) round($w * $ratio));
        $nh     = max(1, (int) round($h * $ratio));
        $scaled = imagecreatetruecolor($nw, $nh);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        if (!imagecopyresampled($scaled, $image, 0, 0, 0, 0, $nw, $nh, $w, $h)) {
            throw new ClubDomainException(ClubDomainException::PHOTO_INVALID, 422);
        }

        return $scaled;
    }

    /** Paint transparency onto white so a PNG/WebP with alpha encodes cleanly as JPEG. */
    private function flatten($image)
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);

        return $canvas;
    }

    private function encodeJpeg(&$image): string
    {
        $image = $this->flatten($image);

        ob_start();
        $ok = imagejpeg($image, null, self::JPEG_QUALITY);
        $binary = (string) ob_get_clean();

        if (!$ok || $binary === '') {
            throw new ClubDomainException(ClubDomainException::PHOTO_INVALID, 422);
        }

        return $binary;
    }
}
