<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use App\Services\ImageVariantService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Display copies of challenge photos (image performance pass, 2026-11-06):
 * smaller files for the app, with the guess geometry, the reveal secrecy and
 * the original uploads all unchanged.
 */
class ImageVariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(BadgeSeeder::class);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** A real, noisy (photo-like, hard to compress) JPEG. */
    private function jpeg(int $w, int $h, int $quality = 92): string
    {
        $img = imagecreatetruecolor($w, $h);
        mt_srand(42);
        for ($y = 0; $y < $h; $y += 6) {
            for ($x = 0; $x < $w; $x += 6) {
                imagefilledrectangle($img, $x, $y, $x + 5, $y + 5, imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        ob_start();
        imagejpeg($img, null, $quality);

        return (string) ob_get_clean();
    }

    /** Insert an EXIF APP1 segment carrying only the Orientation tag. */
    private function withOrientation(string $jpeg, int $orientation, bool $bigEndian = false): string
    {
        $s = $bigEndian ? 'n' : 'v';
        $l = $bigEndian ? 'N' : 'V';
        $tiff = ($bigEndian ? 'MM' : 'II') . pack($s, 42) . pack($l, 8)
            . pack($s, 1)                                               // 1 IFD entry
            . pack($s, 0x0112) . pack($s, 3) . pack($l, 1)              // Orientation, SHORT, count 1
            . pack($s, $orientation) . pack($s, 0)                      // value (padded to 4 bytes)
            . pack($l, 0);                                              // no next IFD
        $payload = "Exif\0\0" . $tiff;

        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    private function store(string $path, string $bytes): string
    {
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    private function challenge(array $attrs = []): Challenge
    {
        return Challenge::create(array_merge([
            'sport_id'            => $this->sport()->id,
            'title'               => 'Corner',
            'ball_x_ratio'        => 0.25,
            'ball_y_ratio'        => 0.75,
            'difficulty'          => 'easy',
            'status'              => 'active',
            'hidden_image_path'   => $this->store('challenges/hidden/' . uniqid('h') . '.jpg', $this->jpeg(2400, 1800)),
            'original_image_path' => $this->store('challenges/original/' . uniqid('o') . '.jpg', $this->jpeg(2400, 1800)),
        ], $attrs));
    }

    private function token(): string
    {
        $user = User::factory()->create(['preferred_sport_id' => $this->sport()->id]);

        return $user->createToken('t')->plainTextToken;
    }

    // ------------------------------------------------------------------
    // service
    // ------------------------------------------------------------------

    public function test_large_photo_is_downscaled_uniformly_and_original_is_untouched(): void
    {
        $bytes = $this->jpeg(4032, 3024);
        $path = $this->store('challenges/hidden/big.jpg', $bytes);

        $meta = (new ImageVariantService())->generate($path, 'challenges/hidden/display', 2048);

        $this->assertSame('challenges/hidden/display/big-w2048-v1.webp', $meta['path']);
        $this->assertSame([2048, 1536], [$meta['width'], $meta['height']]);
        $this->assertLessThan(strlen($bytes), $meta['bytes']);
        $info = getimagesizefromstring(Storage::disk('public')->get($meta['path']));
        $this->assertSame([2048, 1536, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        // Same aspect ratio (no crop / stretch) → ball and guess ratios stay valid.
        $this->assertEqualsWithDelta(4032 / 3024, $info[0] / $info[1], 0.001);
        // The upload itself is byte-for-byte unchanged.
        $this->assertSame($bytes, Storage::disk('public')->get($path));
    }

    public function test_odd_aspect_ratio_drifts_less_than_one_pixel(): void
    {
        $path = $this->store('challenges/hidden/odd.jpg', $this->jpeg(3001, 1999));
        $meta = (new ImageVariantService())->generate($path, 'challenges/hidden/display', 2048);

        // 0..1 ratios map onto the copy with < 1 px error on both axes.
        $this->assertLessThan(1.0, abs($meta['height'] - 1999 * ($meta['width'] / 3001)));
        $this->assertSame(2048, $meta['width']);
    }

    public function test_small_photo_is_never_upscaled(): void
    {
        $path = $this->store('challenges/hidden/small.jpg', $this->jpeg(800, 600));
        $meta = (new ImageVariantService())->generate($path, 'challenges/hidden/display', 2048);

        $this->assertSame([800, 600], [$meta['width'], $meta['height']]);
    }

    public function test_svg_and_missing_files_get_no_copy(): void
    {
        $svg = $this->store('challenges/hidden/a.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');
        $service = new ImageVariantService();

        $this->assertNull($service->generate($svg, 'challenges/hidden/display', 2048));
        $this->assertNull($service->generate('challenges/hidden/nope.jpg', 'challenges/hidden/display', 2048));
    }

    public function test_exif_orientation_is_read_in_both_byte_orders(): void
    {
        $jpeg = $this->jpeg(40, 20);
        $this->assertSame(1, ImageVariantService::jpegOrientation($jpeg));
        $this->assertSame(6, ImageVariantService::jpegOrientation($this->withOrientation($jpeg, 6)));
        $this->assertSame(8, ImageVariantService::jpegOrientation($this->withOrientation($jpeg, 8, true)));
        $this->assertSame(1, ImageVariantService::jpegOrientation('not a jpeg'));
    }

    /**
     * A phone photo stored landscape with "rotate 90° CW" (orientation 6) is
     * shown PORTRAIT by browsers (admin ball picker) and both app platforms.
     * The copy must bake that in, or every ball/guess ratio would be rotated.
     */
    public function test_exif_rotated_photo_is_baked_upright(): void
    {
        // 400x200: left half red, right half blue.
        $img = imagecreatetruecolor(400, 200);
        imagefilledrectangle($img, 0, 0, 199, 199, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 200, 0, 399, 199, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);
        $path = $this->store('challenges/hidden/rot.jpg', $this->withOrientation((string) ob_get_clean(), 6));

        $meta = (new ImageVariantService())->generate($path, 'challenges/hidden/display', 2048);

        $this->assertNotNull($meta['path'], 'a rotated photo always gets an upright copy');
        $this->assertSame([200, 400], [$meta['width'], $meta['height']]);
        $out = imagecreatefromstring(Storage::disk('public')->get($meta['path']));
        // 90° clockwise: the left (red) half ends up on top.
        $top = imagecolorat($out, 100, 50);
        $bottom = imagecolorat($out, 100, 350);
        $this->assertGreaterThan(200, ($top >> 16) & 255);
        $this->assertGreaterThan(200, $bottom & 255);
    }

    // ------------------------------------------------------------------
    // lifecycle + API
    // ------------------------------------------------------------------

    public function test_admin_upload_builds_copies_and_daily_serves_the_guess_copy_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/admin/challenges', [
            'title' => 'Upload', 'difficulty' => 'easy', 'status' => 'active',
            'sport_id' => $this->sport()->id, 'ball_x_ratio' => '0.3', 'ball_y_ratio' => '0.6',
            'hidden_image'   => UploadedFile::fake()->createWithContent('h.jpg', $this->jpeg(3000, 2000, 40)),
            'original_image' => UploadedFile::fake()->createWithContent('o.jpg', $this->jpeg(3000, 2000, 40)),
        ])->assertRedirect('/admin/challenges');

        $challenge = Challenge::firstOrFail();
        $variants = $challenge->image_variants;
        $this->assertStringStartsWith('challenges/hidden/display/', $variants['hidden']['path']);
        $this->assertStringStartsWith('challenges/original/display/', $variants['original']['path']);
        Storage::disk('public')->assertExists([$variants['hidden']['path'], $variants['original']['path']]);
        // Ball position stored exactly as entered.
        $this->assertSame([0.3, 0.6], [$challenge->ball_x_ratio, $challenge->ball_y_ratio]);

        $dc = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);
        $token = $this->token();

        $today = $this->withToken($token)->getJson('/api/daily/today')->assertOk();
        $today->assertJsonPath('daily_challenge.challenge.hidden_image_url', asset('storage/' . $variants['hidden']['path']))
            ->assertJsonPath('daily_challenge.challenge.hidden_image_width', 2048)
            ->assertJsonPath('daily_challenge.challenge.hidden_image_height', 1365);

        // SECURITY: nothing about the reveal image before the guess.
        $raw = $today->getContent();
        $this->assertStringNotContainsString('original', $raw);
        $this->assertStringNotContainsString(pathinfo($challenge->original_image_path, PATHINFO_FILENAME), $raw);
        $this->assertStringNotContainsString('reveal', $raw);
        $this->assertStringNotContainsString('image_variants', $raw);

        // After the guess: the reveal COPY, with its size.
        $guess = $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", ['guess_x_ratio' => 0.3, 'guess_y_ratio' => 0.6])->assertOk();
        $guess->assertJsonPath('data.reveal_image_url', asset('storage/' . $variants['original']['path']))
            ->assertJsonPath('data.reveal_image_width', 2048)
            ->assertJsonPath('data.ball_x_ratio', 0.3)
            ->assertJsonPath('data.score', 100);
    }

    public function test_replacing_the_photo_gives_a_new_url_and_removes_the_old_copy(): void
    {
        $challenge = $this->challenge();
        $oldCopy = $challenge->image_variants['hidden']['path'];
        $oldUrl = $challenge->hiddenImageUrl();

        $challenge->update(['hidden_image_path' => $this->store('challenges/hidden/new.jpg', $this->jpeg(3000, 3000))]);

        $challenge->refresh();
        $this->assertNotSame($oldUrl, $challenge->hiddenImageUrl(), 'cache invalidation relies on a new URL');
        Storage::disk('public')->assertMissing($oldCopy);
        $this->assertSame([2048, 2048], [$challenge->hiddenImageSize()['width'], $challenge->hiddenImageSize()['height']]);
    }

    public function test_stale_or_switched_off_copies_fall_back_to_the_original(): void
    {
        $challenge = $this->challenge();
        $original = asset('storage/' . $challenge->hidden_image_path);
        $this->assertNotSame($original, $challenge->hiddenImageUrl());

        config(['ballspot.images.serve_variants' => false]);
        $this->assertSame($original, $challenge->hiddenImageUrl());
        $this->assertNull($challenge->hiddenImageSize());
        config(['ballspot.images.serve_variants' => true]);

        // Metadata that belongs to another source file is ignored.
        $variants = $challenge->image_variants;
        $variants['hidden']['source'] = 'challenges/hidden/other.jpg';
        $challenge->image_variants = $variants;
        $this->assertSame($original, $challenge->hiddenImageUrl());
    }

    public function test_variant_metadata_is_never_serialised(): void
    {
        $challenge = $this->challenge();
        $this->assertArrayNotHasKey('image_variants', $challenge->toArray());
    }

    public function test_deleting_a_challenge_removes_its_copies(): void
    {
        $challenge = $this->challenge();
        $copies = array_column($challenge->image_variants, 'path');
        $challenge->delete();
        Storage::disk('public')->assertMissing($copies);
    }

    public function test_a_copy_shared_by_two_challenges_survives_deleting_one(): void
    {
        $a = $this->challenge();
        $b = $this->challenge([
            'hidden_image_path' => $a->hidden_image_path,
            'original_image_path' => $a->original_image_path,
        ]);
        $copy = $a->image_variants['hidden']['path'];
        $this->assertSame($copy, $b->image_variants['hidden']['path']);

        $a->delete();
        Storage::disk('public')->assertExists($copy);
        $this->assertSame(asset('storage/' . $copy), $b->fresh()->hiddenImageUrl());

        $b->delete();
        Storage::disk('public')->assertMissing($copy);
    }

    public function test_pack_play_serves_copies_and_next_challenge_is_guess_image_only(): void
    {
        $pack = ChallengePack::create([
            'name' => 'P', 'slug' => 'p', 'status' => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC, 'sport_id' => $this->sport()->id,
        ]);
        $a = $this->challenge(['title' => 'A']);
        $b = $this->challenge(['title' => 'B']);
        $pack->challenges()->attach($a->id, ['sort_order' => 1]);
        $pack->challenges()->attach($b->id, ['sort_order' => 2]);
        $token = $this->token();

        $start = $this->withToken($token)->postJson('/api/packs/p/start')->assertOk();
        $this->assertSame($a->hiddenImageUrl(), $start->json('challenge.hidden_image_url'));
        $this->assertSame(2048, $start->json('challenge.hidden_image_width'));

        $res = $this->withToken($token)->postJson("/api/pack-attempts/{$start->json('attempt.id')}/guess", [
            'challenge_id' => $a->id, 'guessed_x' => 0.25, 'guessed_y' => 0.75,
        ])->assertOk();

        $this->assertSame($a->revealImageUrl(), $res->json('result.reveal_image_url'));
        $this->assertSame($b->hiddenImageUrl(), $res->json('next_challenge.hidden_image_url'));
        // The next challenge's reveal must not ride along with the prefetchable payload.
        $this->assertStringNotContainsString(pathinfo($b->original_image_path, PATHINFO_FILENAME), $res->getContent());
        $this->assertStringNotContainsString(pathinfo($b->image_variants['original']['path'], PATHINFO_FILENAME), $res->getContent());

        // Pack detail list: guess images only.
        $detail = $this->withToken($token)->getJson('/api/packs/p')->assertOk()->getContent();
        $this->assertStringNotContainsString('/original/', $detail);
    }

    public function test_pack_cover_gets_a_smaller_copy(): void
    {
        $cover = $this->store('packs/covers/c.jpg', $this->jpeg(3000, 2000));
        $pack = ChallengePack::create([
            'name' => 'P', 'slug' => 'p', 'status' => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC, 'cover_image_path' => $cover,
        ]);

        $this->assertSame([1200, 800], [$pack->cover_image_variant['width'], $pack->cover_image_variant['height']]);
        $this->assertStringContainsString('packs/covers/display/', $pack->coverImageUrl());
        $this->assertArrayNotHasKey('cover_image_variant', $pack->toArray());

        $copy = $pack->cover_image_variant['path'];
        $pack->delete();
        Storage::disk('public')->assertMissing($copy);
        Storage::disk('public')->assertExists($cover); // the controller owns the original
    }

    public function test_tournament_round_serves_guess_copy_before_and_reveal_copy_after(): void
    {
        $challenge = $this->challenge();
        $user = User::factory()->create();
        $league = \App\Models\League::create([
            'name' => 'L', 'join_code' => 'IMGTEST', 'owner_user_id' => $user->id, 'sport_id' => $this->sport()->id,
            'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active',
        ]);
        \App\Models\LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        $round = \App\Models\LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $challenge->id, 'round_number' => 1, 'status' => 'open']);
        $token = $user->createToken('t')->plainTextToken;

        $current = $this->withToken($token)->getJson("/api/leagues/{$league->id}/current-round")->assertOk();
        $this->assertSame($challenge->hiddenImageUrl(), $current->json('current_round.challenge.hidden_image_url'));
        $this->assertSame(2048, $current->json('current_round.challenge.hidden_image_width'));
        $this->assertStringNotContainsString('/original/', $current->getContent());

        $guess = $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", ['guess_x_ratio' => 0.25, 'guess_y_ratio' => 0.75])->assertStatus(201);
        $this->assertSame($challenge->revealImageUrl(), $guess->json('data.reveal_image_url'));
        $this->assertSame(1536, $guess->json('data.reveal_image_height'));
        $this->assertSame(100, $guess->json('data.score'));
    }

    // ------------------------------------------------------------------
    // commands
    // ------------------------------------------------------------------

    public function test_optimize_backfills_played_challenges_without_touching_originals(): void
    {
        config(['ballspot.images.generate_on_upload' => false]);
        $challenge = $this->challenge();
        $this->assertNull($challenge->image_variants);
        $originalBytes = Storage::disk('public')->get($challenge->hidden_image_path);

        // Played: image/ball are locked; the backfill must still work.
        $dc = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);
        $this->withToken($this->token())->postJson("/api/daily/{$dc->id}/guess", ['guess_x_ratio' => 0.2, 'guess_y_ratio' => 0.7])->assertOk();
        $updatedAt = $challenge->fresh()->updated_at;

        $this->artisan('ballspot:images-optimize', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($challenge->fresh()->image_variants, 'dry run writes nothing');

        $this->artisan('ballspot:images-optimize')->assertSuccessful();
        $fresh = $challenge->fresh();
        $this->assertNotNull($fresh->displayVariant('hidden'));
        $this->assertNotNull($fresh->displayVariant('original'));
        $this->assertSame($originalBytes, Storage::disk('public')->get($fresh->hidden_image_path));
        $this->assertSame([0.25, 0.75], [$fresh->ball_x_ratio, $fresh->ball_y_ratio]);
        $this->assertEquals($updatedAt, $fresh->updated_at);

        // Re-run keeps what exists.
        $before = $fresh->image_variants;
        $this->artisan('ballspot:images-optimize')->assertSuccessful();
        $this->assertSame($before, $challenge->fresh()->image_variants);
    }

    public function test_audit_is_read_only(): void
    {
        config(['ballspot.images.generate_on_upload' => false]);
        $this->challenge();
        $files = Storage::disk('public')->allFiles();

        $this->artisan('ballspot:images-audit')->assertSuccessful();
        $this->artisan('ballspot:images-audit', ['--json' => true])->assertSuccessful();

        $this->assertSame($files, Storage::disk('public')->allFiles());
        $this->assertNull(Challenge::first()->image_variants);
    }

    public function test_recover_command_ignores_display_copies(): void
    {
        $this->challenge();
        $this->artisan('ballspot:recover-challenges')->assertSuccessful();
        $this->assertSame(1, Challenge::count());
    }
}
