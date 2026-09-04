<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use App\Services\DailyHistoryClearService;
use App\Services\DiagnosticsService;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * /admin/diagnostics "Danger zone" → Clear Daily History.
 *
 * The web equivalent of `ballspot:reset-test-daily-history --force
 * --confirm-prelaunch`: admin-only, POST-only, PIN + acknowledgement, backup
 * first, then daily_challenge_guesses + daily_challenges in one transaction.
 * Never challenges, images, usage_pool, tournaments, users, badges or packs.
 */
class ClearDailyHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/diagnostics/clear-daily-history';
    private const PIN = '1281';
    private const ACK = 'I understand this clears Daily history';

    private TestHandler $records;
    private string $backupRoot;
    private string $storageRoot;
    private Challenge $dailyUsed;
    private Challenge $tournamentOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);

        // Backups go to a scratch directory, never the real ../backups folder,
        // and the "storage" that gets copied is a tiny scratch tree too.
        $base = sys_get_temp_dir() . '/ballpicker-clear-daily-' . uniqid();
        $this->backupRoot  = $base . '/backups';
        $this->storageRoot = $base . '/public';
        File::makeDirectory($this->storageRoot . '/challenges/hidden', 0755, true);
        file_put_contents($this->storageRoot . '/challenges/hidden/keep.jpg', 'jpeg-bytes');
        config([
            'ballspot.backup.root'         => $this->backupRoot,
            'ballspot.backup.storage_path' => $this->storageRoot,
        ]);

        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $make  = fn (string $title, string $pool) => Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'usage_pool'        => $pool,
            'hidden_image_path' => 'challenges/hidden/keep.jpg',
        ]);
        $this->dailyUsed      = $make('Was daily', Challenge::POOL_DAILY);
        $this->tournamentOnly = $make('Tournament only', Challenge::POOL_TOURNAMENT);

        $users = User::factory()->count(2)->create();
        foreach (['2026-08-01', '2026-08-02'] as $date) {
            $dc = DailyChallenge::create(['challenge_id' => $this->dailyUsed->id, 'challenge_date' => $date, 'status' => 'archived']);
            foreach ($users as $u) {
                DailyChallengeGuess::create([
                    'daily_challenge_id' => $dc->id, 'user_id' => $u->id,
                    'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.1, 'score' => 50, 'submitted_at' => now(),
                ]);
            }
        }

        $league = League::create(['name' => 'Cup', 'owner_user_id' => $users[0]->id, 'sport_id' => $sport->id, 'join_code' => 'ABC123', 'status' => 'active', 'duration_days' => 7, 'rounds_per_day' => 1, 'starts_at' => now(), 'ends_at' => now()->addDays(7)]);
        $round  = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $this->tournamentOnly->id, 'round_number' => 1, 'status' => 'open']);
        Guess::create([
            'league_round_id' => $round->id, 'user_id' => $users[0]->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.1, 'score' => 50, 'submitted_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->backupRoot));
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    private function assertNothingDeleted(): void
    {
        $this->assertSame(2, DailyChallenge::count());
        $this->assertSame(4, DailyChallengeGuess::count());
        $this->assertDirectoryDoesNotExist($this->backupRoot);
    }

    private function assertUntouched(): void
    {
        $this->assertSame(2, Challenge::count());
        $this->assertSame(Challenge::POOL_DAILY, $this->dailyUsed->fresh()->usage_pool);
        $this->assertSame(Challenge::POOL_TOURNAMENT, $this->tournamentOnly->fresh()->usage_pool);
        $this->assertSame('challenges/hidden/keep.jpg', $this->dailyUsed->fresh()->hidden_image_path);
        $this->assertFileExists($this->storageRoot . '/challenges/hidden/keep.jpg');
        $this->assertSame(1, LeagueRound::count());
        $this->assertSame(1, Guess::count());
        $this->assertSame(1, League::count());
        $this->assertGreaterThanOrEqual(2, User::count());
    }

    private function assertPinNeverLogged(): void
    {
        $dump = json_encode(array_map(fn ($r) => [$r->message, $r->context], $this->records->getRecords()));
        $this->assertStringNotContainsString(self::PIN, $dump);
        $this->assertStringNotContainsString('pin', strtolower(json_encode(array_map(fn ($r) => array_keys($r->context), $this->records->getRecords()))));
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    public function test_guest_cannot_use_the_action(): void
    {
        $this->post(self::URL, ['pin' => self::PIN, 'acknowledge' => '1'])->assertRedirect('/admin/login');
        $this->assertNothingDeleted();
    }

    public function test_non_admin_cannot_use_the_action(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(self::URL, ['pin' => self::PIN, 'acknowledge' => '1'])
            ->assertForbidden();
        $this->assertNothingDeleted();
    }

    public function test_the_destructive_action_is_post_only(): void
    {
        $this->actingAs($this->admin())->get(self::URL)->assertStatus(405);
        $this->assertNothingDeleted();
    }

    public function test_diagnostics_page_stays_read_only_and_shows_the_danger_zone(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/diagnostics')->assertOk()->getContent();

        $this->assertStringContainsString('data-section="danger"', $html);
        $this->assertStringContainsString('Clear Daily History', $html);
        $this->assertStringContainsString('This clears Daily scheduling/history only. It does not delete challenge photos.', $html);
        $this->assertStringContainsString('run Daily scheduling again or use Admin', $html);
        $this->assertStringContainsString(self::URL . '"', $html); // route() renders an absolute URL
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString(self::ACK, $html);
        // Counts before the action.
        $this->assertMatchesRegularExpression('/daily_challenges rows.*?2/s', $html);
        $this->assertMatchesRegularExpression('/daily_challenge_guesses rows.*?4/s', $html);
        $this->assertMatchesRegularExpression('/Affected challenges.*?1/s', $html);
        // The PIN itself never appears — only the fact one is required.
        $this->assertStringNotContainsString(self::PIN, $html);
        $this->assertStringContainsString('confirmation PIN required', $html);
        $this->assertNothingDeleted();
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    public function test_missing_pin_deletes_nothing(): void
    {
        $this->actingAs($this->admin())->from('/admin/diagnostics')
            ->post(self::URL, ['acknowledge' => '1'])
            ->assertRedirect('/admin/diagnostics')
            ->assertSessionHasErrors('pin');

        $this->assertNothingDeleted();
        $this->assertSame('missing_pin', $this->logged('daily_history_clear.denied')[0]->context['reason']);
        $this->assertPinNeverLogged();
    }

    public function test_wrong_pin_deletes_nothing_and_shows_a_friendly_error(): void
    {
        $res = $this->actingAs($this->admin())->from('/admin/diagnostics')
            ->post(self::URL, ['pin' => '00000', 'acknowledge' => '1']);

        $res->assertRedirect('/admin/diagnostics')->assertSessionHas('error');
        $this->assertStringContainsString('PIN', session('error'));
        $this->assertStringNotContainsString('00000', session('error'));

        $this->assertNothingDeleted();
        $denied = $this->logged('daily_history_clear.denied');
        $this->assertSame('wrong_pin', $denied[0]->context['reason']);
        $this->assertArrayHasKey('admin_id', $denied[0]->context);
        $this->assertStringNotContainsString('00000', json_encode($denied[0]->context));
        $this->assertPinNeverLogged();
    }

    public function test_the_previous_pin_is_no_longer_accepted(): void
    {
        $this->actingAs($this->admin())->from('/admin/diagnostics')
            ->post(self::URL, ['pin' => '12811', 'acknowledge' => '1'])
            ->assertRedirect('/admin/diagnostics')
            ->assertSessionHas('error');

        $this->assertNothingDeleted();
        $this->assertSame('wrong_pin', $this->logged('daily_history_clear.denied')[0]->context['reason']);
        $this->assertStringNotContainsString('12811', json_encode(array_map(fn ($r) => $r->context, $this->records->getRecords())));
    }

    public function test_pin_with_surrounding_whitespace_is_accepted(): void
    {
        $this->actingAs($this->admin())->from('/admin/diagnostics')
            ->post(self::URL, ['pin' => ' ' . self::PIN . ' ', 'acknowledge' => '1'])
            ->assertRedirect('/admin/diagnostics')
            ->assertSessionHas('success');

        $this->assertSame(0, DailyChallenge::count());
    }

    public function test_missing_acknowledgement_deletes_nothing(): void
    {
        $this->actingAs($this->admin())->from('/admin/diagnostics')
            ->post(self::URL, ['pin' => self::PIN])
            ->assertRedirect('/admin/diagnostics')
            ->assertSessionHasErrors('acknowledge');

        $this->assertNothingDeleted();
        $this->assertSame('missing_acknowledgement', $this->logged('daily_history_clear.denied')[0]->context['reason']);
        $this->assertPinNeverLogged();
    }

    // ------------------------------------------------------------------
    // Success
    // ------------------------------------------------------------------

    public function test_correct_pin_and_acknowledgement_clears_daily_history_only(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin)->from('/admin/diagnostics')
            ->post(self::URL, ['pin' => self::PIN, 'acknowledge' => '1']);

        $res->assertRedirect('/admin/diagnostics')->assertSessionHas('success');
        $this->assertStringContainsString('2 daily', session('success'));

        $this->assertSame(0, DailyChallenge::count());
        $this->assertSame(0, DailyChallengeGuess::count());
        $this->assertUntouched();
        $this->assertSame(0, Challenge::dailyUsed()->count());

        $done = $this->logged('daily_history_clear.completed');
        $this->assertCount(1, $done);
        $this->assertSame(2, $done[0]->context['deleted_daily_challenges']);
        $this->assertSame(4, $done[0]->context['deleted_daily_challenge_guesses']);
        $this->assertSame(1, $done[0]->context['affected_challenges']);
        $this->assertSame($admin->id, $done[0]->context['admin_id']);
        $this->assertTrue($done[0]->context['backup_created']);
        $this->assertPinNeverLogged();
    }

    public function test_a_content_backup_is_written_before_deleting(): void
    {
        $this->actingAs($this->admin())->post(self::URL, ['pin' => self::PIN, 'acknowledge' => '1']);

        $dirs = File::directories($this->backupRoot);
        $this->assertCount(1, $dirs);
        $backup = $dirs[0];
        $this->assertFileExists($backup . '/manifest.json');
        $this->assertFileExists($backup . '/daily_challenges.json');
        $this->assertFileExists($backup . '/challenges.json');
        $this->assertFileExists($backup . '/storage/challenges/hidden/keep.jpg');

        // The backup captured the rows as they were BEFORE the delete.
        $dailies = json_decode(file_get_contents($backup . '/daily_challenges.json'), true);
        $this->assertCount(2, $dailies);
        $manifest = json_decode(file_get_contents($backup . '/manifest.json'), true);
        $this->assertSame(2, $manifest['daily_challenge_count']);
        $this->assertSame('daily_history_clear', $manifest['reason']);
    }

    public function test_backup_failure_prevents_any_deletion(): void
    {
        // A FILE where the backup root should be → mkdir fails.
        File::ensureDirectoryExists(dirname($this->backupRoot));
        file_put_contents($this->backupRoot, 'not a directory');

        $res = $this->actingAs($this->admin())->from('/admin/diagnostics')
            ->post(self::URL, ['pin' => self::PIN, 'acknowledge' => '1']);

        $res->assertRedirect('/admin/diagnostics')->assertSessionHas('error');
        $this->assertStringContainsString('backup', strtolower(session('error')));
        $this->assertStringNotContainsString($this->backupRoot, session('error'));

        $this->assertSame(2, DailyChallenge::count());
        $this->assertSame(4, DailyChallengeGuess::count());
        $this->assertUntouched();

        $failed = $this->logged('daily_history_clear.failed');
        $this->assertCount(1, $failed);
        $this->assertSame('backup_failed', $failed[0]->context['stage']);
        $this->assertEmpty($this->logged('daily_history_clear.completed'));
        $this->assertPinNeverLogged();
    }

    public function test_delete_failure_is_logged_and_rolled_back(): void
    {
        $service = \Mockery::mock(DailyHistoryClearService::class)->makePartial();
        $service->shouldReceive('deleteRows')->andThrow(new \RuntimeException('db gone at /srv/db'));
        $this->app->instance(DailyHistoryClearService::class, $service);

        $res = $this->actingAs($this->admin())->from('/admin/diagnostics')
            ->post(self::URL, ['pin' => self::PIN, 'acknowledge' => '1']);

        $res->assertRedirect('/admin/diagnostics')->assertSessionHas('error');
        $this->assertStringNotContainsString('/srv/db', session('error'));
        $this->assertSame(2, DailyChallenge::count());
        $this->assertSame(4, DailyChallengeGuess::count());

        $failed = $this->logged('daily_history_clear.failed');
        $this->assertSame('delete_failed', $failed[0]->context['stage']);
        $this->assertSame('RuntimeException', $failed[0]->context['exception']);
        $this->assertStringNotContainsString('/srv/db', json_encode($failed[0]->context));
    }

    public function test_diagnostics_counts_update_after_clearing(): void
    {
        $admin = $this->admin();
        $before = app(DiagnosticsService::class)->snapshot();
        $this->assertSame(2, $before['prelaunch']['daily_challenges']);
        $this->assertSame(4, $before['prelaunch']['daily_challenge_guesses']);
        $this->assertSame(1, $before['prelaunch']['affected_challenges']);
        $usedBefore = (int) collect($before['content']['per_sport'])->sum('used_as_daily');
        $this->assertSame(1, $usedBefore);
        $poolBefore = (int) $before['daily']['pool_available'];

        $this->actingAs($admin)->post(self::URL, ['pin' => self::PIN, 'acknowledge' => '1']);

        $after = app(DiagnosticsService::class)->snapshot();
        $this->assertSame(0, $after['prelaunch']['daily_challenges']);
        $this->assertSame(0, $after['prelaunch']['daily_challenge_guesses']);
        $this->assertSame(0, $after['prelaunch']['affected_challenges']);
        $this->assertSame(0, (int) collect($after['content']['per_sport'])->sum('used_as_daily'));
        $this->assertSame($poolBefore + 1, (int) $after['daily']['pool_available']);
        $this->assertSame('none', $after['daily']['today_status']);
        $this->assertNull($after['daily']['latest_scheduled_date']);

        $html = $this->actingAs($admin)->get('/admin/diagnostics')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/daily_challenges rows.*?0/s', $html);
        $this->assertStringContainsString('Nothing to clear', $html);
    }

    public function test_nothing_to_clear_still_requires_pin_and_reports_cleanly(): void
    {
        DailyChallengeGuess::query()->delete();
        DailyChallenge::query()->delete();

        $this->actingAs($this->admin())->from('/admin/diagnostics')
            ->post(self::URL, ['pin' => self::PIN, 'acknowledge' => '1'])
            ->assertRedirect('/admin/diagnostics')
            ->assertSessionHas('success');

        $this->assertStringContainsString('0 daily', session('success'));
        $this->assertUntouched();
    }
}
