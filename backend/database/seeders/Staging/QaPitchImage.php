<?php

namespace Database\Seeders\Staging;

use Illuminate\Http\UploadedFile;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Synthetic QA challenge images (GD only, no external assets): a striped
 * green pitch with white lines and a few "players", plus — for the original —
 * a white ball at a seeded random position. The hidden image is the very same
 * picture without the ball, so the stored ball ratios are exact.
 *
 * Same seed → same picture and ball position (re-runs are reproducible);
 * different seeds → different bytes (per-club photo duplicate check).
 */
final class QaPitchImage
{
    public const WIDTH  = 1200;
    public const HEIGHT = 800;

    /** @var list<string> temp files handed out as uploads */
    private array $tempFiles = [];

    /** @return array{x: float, y: float} ball centre as 0..1 ratios (3 decimals, like ChallengeCreator stores them) */
    public static function ballPosition(int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));

        return [
            'x' => round(0.12 + $random->getInt(0, 760) / 1000, 3),
            'y' => round(0.15 + $random->getInt(0, 700) / 1000, 3),
        ];
    }

    /** JPEG bytes of the pitch, with or without the ball. */
    public static function jpeg(int $seed, bool $withBall, string $label = ''): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;
        $img = imagecreatetruecolor($w, $h);
        $random = new Randomizer(new Mt19937($seed ^ 0x5eed));

        // Mowing stripes with a seeded tint.
        $tint = $random->getInt(0, 18);
        $light = imagecolorallocate($img, 46 + $tint, 140 + $tint, 58);
        $dark  = imagecolorallocate($img, 38 + $tint, 122 + $tint, 50);
        for ($i = 0, $x = 0; $x < $w; $i++, $x += 100) {
            imagefilledrectangle($img, $x, 0, $x + 99, $h, $i % 2 ? $dark : $light);
        }

        // Lines.
        $white = imagecolorallocate($img, 245, 245, 245);
        imagesetthickness($img, 4);
        $m = 40;
        imagerectangle($img, $m, $m, $w - $m, $h - $m, $white);
        imageline($img, (int) ($w / 2), $m, (int) ($w / 2), $h - $m, $white);
        for ($r = 178; $r <= 184; $r++) {
            imageellipse($img, (int) ($w / 2), (int) ($h / 2), $r, $r, $white);
        }
        imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), 10, 10, $white);
        foreach ([$m, $w - $m] as $goalLine) {
            $dir = $goalLine === $m ? 1 : -1;
            imagerectangle($img, min($goalLine, $goalLine + $dir * 180), (int) ($h / 2 - 220), max($goalLine, $goalLine + $dir * 180), (int) ($h / 2 + 220), $white);
            imagerectangle($img, min($goalLine, $goalLine + $dir * 60), (int) ($h / 2 - 100), max($goalLine, $goalLine + $dir * 60), (int) ($h / 2 + 100), $white);
            imagefilledellipse($img, $goalLine + $dir * 120, (int) ($h / 2), 10, 10, $white);
        }
        imagesetthickness($img, 1);

        // Players (identical in both images).
        $home = imagecolorallocate($img, 200, 30, 40);
        $away = imagecolorallocate($img, 30, 70, 200);
        $skin = imagecolorallocate($img, 230, 190, 150);
        for ($p = 0; $p < 12; $p++) {
            $px = $random->getInt(80, $w - 80);
            $py = $random->getInt(80, $h - 80);
            imagefilledellipse($img, $px, $py + 14, 22, 30, $p % 2 ? $home : $away);
            imagefilledellipse($img, $px, $py - 6, 14, 14, $skin);
        }

        if ($label !== '') {
            imagestring($img, 5, $m + 12, $m + 10, $label, $white);
        }

        if ($withBall) {
            $ball = self::ballPosition($seed);
            $bx = (int) round($ball['x'] * $w);
            $by = (int) round($ball['y'] * $h);
            $shadow = imagecolorallocate($img, 20, 70, 28);
            $black  = imagecolorallocate($img, 15, 15, 15);
            imagefilledellipse($img, $bx + 4, $by + 6, 24, 14, $shadow);
            imagefilledellipse($img, $bx, $by, 22, 22, $white);
            imageellipse($img, $bx, $by, 22, 22, $black);
            imagefilledpolygon($img, [$bx, $by - 4, $bx + 4, $by - 1, $bx + 3, $by + 4, $bx - 3, $by + 4, $bx - 4, $by - 1], $black);
        }

        ob_start();
        imagejpeg($img, null, 88);

        return (string) ob_get_clean();
    }

    /** The image as an UploadedFile (test-mode upload of a temp file), the way the admin form hands it to the services. */
    public function upload(int $seed, bool $withBall, string $label = ''): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bpqa_');
        file_put_contents($path, self::jpeg($seed, $withBall, $label));
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'qa-pitch-' . $seed . ($withBall ? '' : '-hidden') . '.jpg', 'image/jpeg', null, true);
    }

    /** Remove the temp files handed out so far. */
    public function cleanup(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }
}
