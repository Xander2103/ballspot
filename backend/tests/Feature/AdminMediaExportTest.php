<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\League;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use App\Services\MediaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Admin → Media Export: admin-only, filterable, read-only ZIP of challenge
 * images + manifests. Missing files are reported, traversal is impossible,
 * nothing secret is ever packed.
 */
class AdminMediaExportTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = MediaExportService::ROOT_DIR;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // Leftovers from earlier runs (the test client never streams a download).
        foreach (glob(storage_path('app/exports/*.zip')) ?: [] as $stale) {
            @unlink($stale);
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function sport(string $slug = 'football'): Sport
    {
        return Sport::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'emoji' => '⚽', 'status' => Sport::STATUS_ACTIVE]);
    }

    /** A challenge whose image files really exist on the (fake) public disk. */
    private function challenge(Sport $sport, string $title, array $overrides = [], bool $withGuess = true, bool $withReveal = true): Challenge
    {
        $slug = \Illuminate\Support\Str::slug($title);
        $guess  = $withGuess ? "challenges/hidden/{$slug}.jpg" : null;
        $reveal = $withReveal ? "challenges/original/{$slug}.png" : null;
        if ($guess) {
            Storage::disk('public')->put($guess, "GUESS-{$title}");
        }
        if ($reveal) {
            Storage::disk('public')->put($reveal, "REVEAL-{$title}");
        }

        return Challenge::create(array_merge([
            'sport_id'            => $sport->id,
            'title'               => $title,
            'ball_x_ratio'        => 0.5,
            'ball_y_ratio'        => 0.5,
            'difficulty'          => 'easy',
            'status'              => 'active',
            'usage_pool'          => Challenge::POOL_GENERAL,
            'hidden_image_path'   => $guess,
            'original_image_path' => $reveal,
        ], $overrides));
    }

    /** POST the download and return the opened ZipArchive (+ its temp path). */
    private function downloadZip(User $admin, array $filters = []): array
    {
        $res = $this->actingAs($admin)->post('/admin/media-export/download', $filters);
        $res->assertOk();
        $this->assertSame('application/zip', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('ballpicker-media-export-', $res->headers->get('Content-Disposition'));

        // BinaryFileResponse: copy the file before the after-send delete.
        $file = $res->baseResponse->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'bpzip');
        copy($file, $copy);
        // Actually send it (into a discarded buffer) so deleteFileAfterSend runs.
        ob_start();
        $res->baseResponse->sendContent();
        ob_end_clean();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($copy) === true, 'zip opens');

        return [$zip, $copy];
    }

    private function names(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        return $names;
    }

    // ---------------------------------------------------------------- access

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/media-export')->assertRedirect('/admin/login');
        $this->post('/admin/media-export/download')->assertRedirect('/admin/login');
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/admin/media-export')->assertForbidden();
        $this->actingAs($user)->post('/admin/media-export/download')->assertForbidden();
    }

    public function test_admin_can_open_the_page_with_counts_and_preview(): void
    {
        $admin = $this->admin();
        $sport = $this->sport();
        $this->challenge($sport, 'Alpha');
        $this->challenge($sport, 'Beta', [], true, false);

        $res = $this->actingAs($admin)->get('/admin/media-export');
        $res->assertOk()
            ->assertSee('Media Export')
            ->assertSee('Download ZIP')
            ->assertSee('Alpha')
            ->assertSee('Beta');
        $this->assertSame(2, $res->viewData('summary')['challenges']);
        $this->assertSame(2, $res->viewData('summary')['guess_images']);
        $this->assertSame(1, $res->viewData('summary')['reveal_images']);
        $this->assertSame(0, $res->viewData('summary')['missing']);
    }

    // --------------------------------------------------------------- filters

    public function test_filters_by_sport(): void
    {
        $admin  = $this->admin();
        $foot   = $this->sport('football');
        $tennis = $this->sport('tennis');
        $this->challenge($foot, 'Foot One');
        $this->challenge($tennis, 'Tennis One');

        $res = $this->actingAs($admin)->get('/admin/media-export?sport=' . $tennis->id);
        $this->assertSame(1, $res->viewData('summary')['challenges']);
        $this->assertSame('Tennis One', $res->viewData('preview')->first()->title);
    }

    public function test_filters_by_usage_pool_and_status(): void
    {
        $admin = $this->admin();
        $sport = $this->sport();
        $this->challenge($sport, 'Daily pool', ['usage_pool' => Challenge::POOL_DAILY]);
        $this->challenge($sport, 'Pack pool', ['usage_pool' => Challenge::POOL_PACK]);
        $this->challenge($sport, 'Draft one', ['status' => 'draft']);

        $this->assertSame(1, $this->actingAs($admin)->get('/admin/media-export?usage_pool=pack')->viewData('summary')['challenges']);
        $this->assertSame(1, $this->actingAs($admin)->get('/admin/media-export?status=draft')->viewData('summary')['challenges']);
        // Unknown values are ignored, never an error.
        $this->assertSame(3, $this->actingAs($admin)->get('/admin/media-export?usage_pool=bogus&status=weird')->viewData('summary')['challenges']);
    }

    public function test_filters_by_daily_usage(): void
    {
        $admin = $this->admin();
        $sport = $this->sport();
        $used  = $this->challenge($sport, 'Was Daily');
        $this->challenge($sport, 'Never Daily');
        DailyChallenge::create(['challenge_id' => $used->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        $yes = $this->actingAs($admin)->get('/admin/media-export?used_as_daily=yes');
        $this->assertSame(['Was Daily'], $yes->viewData('preview')->pluck('title')->all());
        $no = $this->actingAs($admin)->get('/admin/media-export?used_as_daily=no');
        $this->assertSame(['Never Daily'], $no->viewData('preview')->pluck('title')->all());
    }

    public function test_filters_by_pack_and_tournament_usage(): void
    {
        $admin = $this->admin();
        $sport = $this->sport();
        $inPack = $this->challenge($sport, 'In Pack');
        $inTour = $this->challenge($sport, 'In Tournament');
        $this->challenge($sport, 'Loose');

        $pack = ChallengePack::create(['name' => 'Starter', 'slug' => 'starter', 'status' => ChallengePack::STATUS_ACTIVE, 'visibility' => ChallengePack::VISIBILITY_PUBLIC, 'sport_id' => $sport->id]);
        $pack->challenges()->attach($inPack->id, ['sort_order' => 1]);
        $owner  = User::factory()->create();
        $league = League::create(['name' => 'L', 'join_code' => 'MEDIA1', 'owner_user_id' => $owner->id, 'sport_id' => $sport->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active']);
        LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $inTour->id, 'round_number' => 1, 'status' => 'open']);

        $get = fn (string $q) => $this->actingAs($admin)->get('/admin/media-export?' . $q)->viewData('preview')->pluck('title')->all();
        $this->assertSame(['In Pack'], $get('pack=any'));
        $this->assertSame(['In Pack'], $get('pack=' . $pack->id));
        $this->assertSame(['In Tournament', 'Loose'], $get('pack=none'));
        $this->assertSame(['In Tournament'], $get('tournament=used'));
        $this->assertSame(['In Pack', 'Loose'], $get('tournament=not_used'));
    }

    public function test_filters_by_image_type(): void
    {
        $admin = $this->admin();
        $sport = $this->sport();
        $this->challenge($sport, 'Both');
        $this->challenge($sport, 'Guess Only', [], true, false);
        $this->challenge($sport, 'Reveal Only', [], false, true);

        $both   = $this->actingAs($admin)->get('/admin/media-export?image_type=both')->viewData('summary');
        $guess  = $this->actingAs($admin)->get('/admin/media-export?image_type=guess')->viewData('summary');
        $reveal = $this->actingAs($admin)->get('/admin/media-export?image_type=reveal')->viewData('summary');

        $this->assertSame([3, 2, 2], [$both['challenges'], $both['guess_images'], $both['reveal_images']]);
        $this->assertSame([2, 2, 0], [$guess['challenges'], $guess['guess_images'], $guess['reveal_images']]);
        $this->assertSame([2, 0, 2], [$reveal['challenges'], $reveal['guess_images'], $reveal['reveal_images']]);
    }

    // ------------------------------------------------------------------- zip

    public function test_zip_contains_manifests_images_and_metadata_in_the_documented_layout(): void
    {
        $admin = $this->admin();
        $sport = $this->sport();
        $c = $this->challenge($sport, 'Corner Kick', ['usage_pool' => Challenge::POOL_TOURNAMENT]);
        $pack = ChallengePack::create(['name' => 'Legends', 'slug' => 'legends', 'status' => ChallengePack::STATUS_ACTIVE, 'visibility' => ChallengePack::VISIBILITY_PUBLIC, 'sport_id' => $sport->id]);
        $pack->challenges()->attach($c->id, ['sort_order' => 1]);
        DailyChallenge::create(['challenge_id' => $c->id, 'challenge_date' => '2026-03-04', 'status' => 'archived']);

        [$zip, $tmp] = $this->downloadZip($admin);
        $names = $this->names($zip);

        $folder = self::ROOT . "/challenges/football/tournament/challenge-{$c->id}-corner-kick";
        $this->assertContains(self::ROOT . '/manifest.json', $names);
        $this->assertContains(self::ROOT . '/manifest.csv', $names);
        $this->assertContains("{$folder}/guess.jpg", $names);
        $this->assertContains("{$folder}/reveal.png", $names);
        $this->assertContains("{$folder}/metadata.json", $names);
        $this->assertSame('GUESS-Corner Kick', $zip->getFromName("{$folder}/guess.jpg"));
        $this->assertSame('REVEAL-Corner Kick', $zip->getFromName("{$folder}/reveal.png"));

        $manifest = json_decode($zip->getFromName(self::ROOT . '/manifest.json'), true);
        $this->assertSame(1, $manifest['challenge_count']);
        $this->assertSame(0, $manifest['missing_count']);
        $row = $manifest['challenges'][0];
        $this->assertSame($c->id, $row['challenge_id']);
        $this->assertSame('Corner Kick', $row['title']);
        $this->assertSame('football', $row['sport_slug']);
        $this->assertSame('tournament', $row['usage_pool']);
        $this->assertTrue($row['has_guess_image']);
        $this->assertTrue($row['has_reveal_image']);
        $this->assertTrue($row['used_as_daily']);
        $this->assertSame(['2026-03-04'], $row['daily_dates']);
        $this->assertTrue($row['used_in_packs']);
        $this->assertSame(['Legends'], $row['pack_names']);
        $this->assertFalse($row['used_in_tournaments']);
        $this->assertSame(['guess' => 'guess.jpg', 'reveal' => 'reveal.png'], $row['exported_files']);
        // Storage-relative paths only — never a server directory.
        $this->assertSame("challenges/hidden/corner-kick.jpg", $row['guess_image_path']);
        $this->assertStringNotContainsString(storage_path(), json_encode($manifest));

        $csv = $zip->getFromName(self::ROOT . '/manifest.csv');
        $this->assertStringStartsWith('id,title,sport,sport_slug,usage_pool,status,difficulty,has_guess_image,has_reveal_image,used_as_daily,used_in_packs,pack_names,used_in_tournaments', $csv);
        $this->assertStringContainsString("{$c->id},\"Corner Kick\",Football,football,tournament,active,easy,yes,yes,yes,yes,Legends,no", $csv);

        $meta = json_decode($zip->getFromName("{$folder}/metadata.json"), true);
        $this->assertSame($c->id, $meta['challenge_id']);

        $zip->close();
        @unlink($tmp);
    }

    public function test_image_type_filter_limits_which_files_are_packed(): void
    {
        $admin = $this->admin();
        $c = $this->challenge($this->sport(), 'Only Guess Please');

        [$zip, $tmp] = $this->downloadZip($admin, ['image_type' => 'guess']);
        $names = $this->names($zip);
        $folder = self::ROOT . "/challenges/football/general/challenge-{$c->id}-only-guess-please";
        $this->assertContains("{$folder}/guess.jpg", $names);
        $this->assertNotContains("{$folder}/reveal.png", $names);
        $zip->close();
        @unlink($tmp);
    }

    public function test_missing_image_file_is_reported_not_fatal(): void
    {
        $admin = $this->admin();
        $c = $this->challenge($this->sport(), 'Ghost');
        Storage::disk('public')->delete($c->original_image_path);

        $page = $this->actingAs($admin)->get('/admin/media-export');
        $this->assertSame(1, $page->viewData('summary')['missing']);

        [$zip, $tmp] = $this->downloadZip($admin);
        $names = $this->names($zip);
        $folder = self::ROOT . "/challenges/football/general/challenge-{$c->id}-ghost";
        $this->assertContains("{$folder}/guess.jpg", $names);
        $this->assertNotContains("{$folder}/reveal.png", $names);

        $manifest = json_decode($zip->getFromName(self::ROOT . '/manifest.json'), true);
        $this->assertSame(1, $manifest['missing_count']);
        $this->assertSame('reveal', $manifest['missing_files'][0]['kind']);
        $this->assertSame(['reveal'], $manifest['challenges'][0]['missing_files']);
        $this->assertStringContainsString(',reveal,', $zip->getFromName(self::ROOT . '/manifest.csv'));
        $zip->close();
        @unlink($tmp);
    }

    public function test_path_traversal_and_absolute_paths_are_treated_as_missing(): void
    {
        $admin = $this->admin();
        $sport = $this->sport();
        // Something we must never pack, sitting outside the public disk.
        $secret = storage_path('app/private-secret-' . uniqid() . '.txt');
        file_put_contents($secret, 'TOP SECRET');

        Challenge::create([
            'sport_id' => $sport->id, 'title' => 'Evil', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active',
            'hidden_image_path'   => '../../' . basename(dirname($secret)) . '/' . basename($secret),
            'original_image_path' => $secret,
        ]);
        Challenge::create([
            'sport_id' => $sport->id, 'title' => 'Env', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active',
            'hidden_image_path'   => '../../../.env',
            'original_image_path' => 'file:///etc/passwd',
        ]);
        $ok = $this->challenge($sport, 'Fine');

        $service = app(MediaExportService::class);
        $this->assertNull($service->resolve('../../' . basename($secret)));
        $this->assertNull($service->resolve($secret));
        $this->assertNull($service->resolve('../../../.env'));
        $this->assertNull($service->resolve('file:///etc/passwd'));
        $this->assertNotNull($service->resolve($ok->hidden_image_path));

        [$zip, $tmp] = $this->downloadZip($admin);
        $all = implode("\n", $this->names($zip));
        $this->assertStringNotContainsString('.env', $all);
        $this->assertStringNotContainsString('passwd', $all);
        $this->assertStringNotContainsString('secret', $all);
        $this->assertStringNotContainsString('.log', $all);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringNotContainsString('TOP SECRET', (string) $zip->getFromIndex($i));
        }
        $manifest = json_decode($zip->getFromName(self::ROOT . '/manifest.json'), true);
        $this->assertSame(4, $manifest['missing_count']);
        $zip->close();
        @unlink($tmp);
        @unlink($secret);
    }

    public function test_empty_result_shows_a_friendly_message_and_refuses_the_download(): void
    {
        $admin = $this->admin();
        $this->challenge($this->sport(), 'Only Football');

        $zipsBefore = count(glob(storage_path('app/exports/*.zip')) ?: []);
        $page = $this->actingAs($admin)->get('/admin/media-export?search=nothing-matches');
        $page->assertOk()->assertSee('No challenges match these filters')->assertDontSee('Download ZIP');

        $this->actingAs($admin)->post('/admin/media-export/download', ['search' => 'nothing-matches'])
            ->assertRedirect()
            ->assertSessionHasErrors('export');
        $this->assertCount($zipsBefore, glob(storage_path('app/exports/*.zip')) ?: [], 'no empty zip is generated');
    }

    public function test_too_many_files_is_refused_with_a_warning(): void
    {
        config(['ballspot.media_export.max_files' => 3]);
        $admin = $this->admin();
        $sport = $this->sport();
        $this->challenge($sport, 'One');
        $this->challenge($sport, 'Two');

        $page = $this->actingAs($admin)->get('/admin/media-export');
        $page->assertOk()->assertSee('Too many files')->assertDontSee('Download ZIP');
        $this->assertTrue($page->viewData('summary')['too_many']);

        $this->actingAs($admin)->post('/admin/media-export/download')->assertRedirect()->assertSessionHasErrors('export');

        // Narrowing to one image kind fits again.
        $this->actingAs($admin)->get('/admin/media-export?image_type=guess')->assertSee('Download ZIP');
    }

    public function test_export_never_modifies_challenges_and_cleans_up_its_temp_file(): void
    {
        $admin = $this->admin();
        $sport = $this->sport();
        $c = $this->challenge($sport, 'Untouched');
        $before = Challenge::query()->orderBy('id')->get()->map->only(['id', 'title', 'status', 'usage_pool', 'hidden_image_path', 'original_image_path', 'updated_at'])->toArray();

        [$zip, $tmp] = $this->downloadZip($admin);
        $zip->close();
        @unlink($tmp);

        $after = Challenge::query()->orderBy('id')->get()->map->only(['id', 'title', 'status', 'usage_pool', 'hidden_image_path', 'original_image_path', 'updated_at'])->toArray();
        $this->assertEquals($before, $after);
        $this->assertTrue(Storage::disk('public')->exists($c->hidden_image_path));
        // Temp zip is deleted after the response is sent.
        $this->assertSame([], glob(storage_path('app/exports/*.zip')) ?: []);
    }
}
