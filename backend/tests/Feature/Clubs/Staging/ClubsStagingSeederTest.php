<?php

namespace Tests\Feature\Clubs\Staging;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubStatus;
use App\Enums\CompetitionPhase;
use App\Enums\PhotoSubmissionStatus;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubContentReport;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\ClubPhotoSubmission;
use App\Models\PackAttempt;
use App\Models\Sport;
use App\Models\User;
use App\Services\Clubs\ClubStandingsService;
use Database\Seeders\ClubsStagingSeeder;
use Database\Seeders\Staging\StagingSeedGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ClubsStagingSeeder: the guard refuses (writing nothing) for every failing
 * condition; with all conditions met it seeds the QA story through the real
 * services, idempotently, without mail / push / HTTP, with club images on the
 * private disk only.
 *
 * The in-memory test database has no name, so the "database name contains
 * staging" check is satisfied by a subclass that overrides the seeder's
 * databaseName() seam (StagingNamedClubsStagingSeeder below) — there is no
 * env/config switch for it.
 */
class ClubsStagingSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'qa-Staging-Pass-2026';

    private const ENV_KEYS = [
        StagingSeedGuard::ENV_CONFIRM, StagingSeedGuard::ENV_PASSWORD, StagingSeedGuard::ENV_DRY_RUN,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('club_private');
        Mail::fake();
        Notification::fake();
        Http::fake();
        Http::preventStrayRequests();
        config(['ballspot.notifications.push_enabled' => true, 'app.url' => 'https://staging.ballpicker.vanmalderstudio.be']);

        $this->setEnv(StagingSeedGuard::ENV_CONFIRM, 'yes');
        $this->setEnv(StagingSeedGuard::ENV_PASSWORD, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->setEnv($key, null);
        }
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    // ------------------------------------------------------------ guard

    public static function refusals(): array
    {
        return [
            'env not staging'      => [fn (self $t) => $t->app['env'] = 'production', 'APP_ENV must be "staging"'],
            'missing confirmation' => [fn (self $t) => $t->setEnv(StagingSeedGuard::ENV_CONFIRM, null), 'BALLPICKER_STAGING_SEED_CONFIRM=yes'],
            'confirmation not yes' => [fn (self $t) => $t->setEnv(StagingSeedGuard::ENV_CONFIRM, 'true'), 'BALLPICKER_STAGING_SEED_CONFIRM=yes'],
            'production APP_URL'   => [fn () => config(['app.url' => 'https://ballpicker.vanmalderstudio.be']), 'APP_URL'],
            'prod-like subdomain'  => [fn () => config(['app.url' => 'https://api.ballpicker.vanmalderstudio.be']), 'APP_URL'],
            'non-QA user present'  => [fn () => User::factory()->create(['email' => 'real.person@example.com']), 'outside @staging.ballpicker.test'],
            'QA-like but other domain' => [fn () => User::factory()->create(['username' => 'qa_fake', 'email' => 'qa_fake@staging.ballpicker.test.evil.test']), 'outside @staging.ballpicker.test'],
            'missing password'     => [fn (self $t) => $t->setEnv(StagingSeedGuard::ENV_PASSWORD, null), 'BALLPICKER_STAGING_SEED_PASSWORD'],
            'short password'       => [fn (self $t) => $t->setEnv(StagingSeedGuard::ENV_PASSWORD, 'short-pass1'), 'BALLPICKER_STAGING_SEED_PASSWORD'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_guard_refuses_and_writes_nothing(\Closure $break, string $message): void
    {
        $this->app['env'] = 'staging';
        $break($this);
        $usersBefore = User::count();

        $this->assertRefused(StagingNamedClubsStagingSeeder::class, $message);
        $this->assertNothingSeeded($usersBefore);
    }

    public function test_guard_refuses_a_database_name_without_staging(): void
    {
        $this->app['env'] = 'staging';

        $this->assertRefused(ClubsStagingSeeder::class, 'database name must contain "staging"');
        $this->assertNothingSeeded(0);
    }

    public function test_guard_accepts_only_the_exact_staging_host_for_the_production_domain(): void
    {
        $this->assertTrue(StagingSeedGuard::appUrlAllowed('https://staging.ballpicker.vanmalderstudio.be'));
        $this->assertTrue(StagingSeedGuard::appUrlAllowed('http://localhost:8000'));
        $this->assertTrue(StagingSeedGuard::appUrlAllowed('https://bp-staging.example.test'));
        $this->assertFalse(StagingSeedGuard::appUrlAllowed('https://ballpicker.vanmalderstudio.be'));
        $this->assertFalse(StagingSeedGuard::appUrlAllowed('https://www.ballpicker.vanmalderstudio.be/'));
        $this->assertFalse(StagingSeedGuard::appUrlAllowed('https://staging.ballpicker.vanmalderstudio.be.evil.test'));
        $this->assertFalse(StagingSeedGuard::appUrlAllowed('https://evil.test/?ballpicker.vanmalderstudio.be'));
    }

    public function test_dry_run_prints_the_plan_and_writes_nothing(): void
    {
        $this->app['env'] = 'staging';
        $this->setEnv(StagingSeedGuard::ENV_DRY_RUN, 'yes');

        Artisan::call('db:seed', ['--class' => StagingNamedClubsStagingSeeder::class, '--force' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('QA accounts', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
        $this->assertNothingSeeded(0);
    }

    // ------------------------------------------------------------ full seed

    public function test_seeds_the_qa_story_idempotently_without_mail_push_or_http(): void
    {
        $this->app['env'] = 'staging';
        $testMail = Mail::getFacadeRoot();

        $output = $this->runSeeder();

        // --- isolation: the seeder installed its own fakes, nothing went out, flags restored.
        $this->assertInstanceOf(MailFake::class, Mail::getFacadeRoot());
        $this->assertNotSame($testMail, Mail::getFacadeRoot());
        $this->assertInstanceOf(NotificationFake::class, Notification::getFacadeRoot());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Http::assertNothingSent();
        $this->assertTrue(config('ballspot.notifications.push_enabled'), 'push flag restored after seeding');
        $this->assertFalse((bool) config('ballspot.clubs.enabled'), 'clubs flag restored after seeding');
        $this->assertNull(\Illuminate\Support\Carbon::getTestNow(), 'clock restored after seeding');

        // --- output: names, no secrets.
        $this->assertStringContainsString('qa_many_clubs', $output);
        $this->assertStringContainsString('QA FC Alpha', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
        foreach (ClubInviteCode::all() as $code) {
            $this->assertStringNotContainsString($code->code, $output);
        }

        // --- accounts.
        $accounts = ClubsStagingSeeder::accounts();
        $this->assertCount(24, $accounts);
        $this->assertSame(24, User::count());
        foreach (User::all() as $user) {
            $this->assertStringStartsWith('qa_', $user->username);
            $this->assertTrue(StagingSeedGuard::isQaEmail($user->email));
            $this->assertNotNull($user->email_verified_at);
            $this->assertNotNull($user->terms_accepted_at);
            $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        }
        $this->assertSame(['de', 'en', 'es', 'fr', 'nl'], User::distinct()->orderBy('preferred_language')->pluck('preferred_language')->all());
        $this->assertTrue((bool) User::where('username', 'qa_admin')->first()->is_admin);
        $this->assertSame(1, User::where('is_admin', true)->count());

        // --- base content.
        $this->assertTrue(Sport::where('slug', 'football')->exists());
        $this->assertGreaterThan(0, Challenge::whereNull('club_id')->count());

        // --- clubs.
        $alpha = Club::where('slug', 'qa-fc-alpha')->firstOrFail();
        $this->assertSame(ClubStatus::Active, $alpha->status);
        $this->assertSame('Gent', $alpha->city);
        $this->assertSame('members', $alpha->settings['member_list_visibility']);
        $this->assertSame(ClubStatus::Suspended, Club::where('slug', 'qa-beta-hockey')->first()->status);
        $this->assertSame(ClubStatus::Archived, Club::where('slug', 'qa-gamma-school')->first()->status);
        $delta = Club::where('slug', 'qa-delta-empty')->firstOrFail();
        $this->assertTrue((bool) $delta->needs_owner);
        $this->assertSame(0, ChallengePack::where('club_id', $delta->id)->count());
        $this->assertSame(0, ClubCompetition::where('club_id', $delta->id)->count());
        $this->assertSame(34, Club::count());
        $this->assertSame(30, Club::where('name', 'like', 'QA Club %')->distinct()->count('city'));

        // --- Alpha members.
        $status = fn (string $username) => ClubMember::where('club_id', $alpha->id)->where('user_id', User::where('username', $username)->value('id'))->value('status');
        $role = fn (string $username) => ClubMember::where('club_id', $alpha->id)->where('user_id', User::where('username', $username)->value('id'))->value('role');
        $this->assertSame(ClubMemberStatus::Banned->value, $this->enumValue($status('qa_banned')));
        $this->assertSame(ClubMemberStatus::Left->value, $this->enumValue($status('qa_left')));
        $this->assertSame('owner', $this->enumValue($role('qa_owner_alpha')));
        $this->assertSame('admin', $this->enumValue($role('qa_admin_alpha')));
        $this->assertNull($status('qa_outsider'));
        $this->assertSame(0, ClubMember::where('user_id', User::where('username', 'qa_outsider')->value('id'))->count());
        $this->assertSame(18, $alpha->members_count);

        // --- invite codes: usable member, usable admin, expired, revoked, exhausted; one usable on Delta.
        $codes = ClubInviteCode::where('club_id', $alpha->id)->get()->keyBy('label');
        $this->assertCount(5, $codes);
        $this->assertSame(1, ClubInviteCode::usable()->where('club_id', $alpha->id)->where('grants_role', 'member')->where('label', 'QA member code')->count());
        $this->assertSame('admin', $this->enumValue($codes['QA admin code']->grants_role));
        $this->assertSame(1, ClubInviteCode::usable()->whereKey($codes['QA admin code']->id)->count());
        $this->assertTrue($codes['QA expired code']->isExpired());
        $this->assertTrue($codes['QA revoked code']->isRevoked());
        $this->assertTrue($codes['QA exhausted code']->isExhausted());
        $this->assertSame(1, ClubInviteCode::usable()->where('club_id', $delta->id)->count());

        // --- challenges: GD images on the private disk only, ball ratios stored.
        $clubChallenges = Challenge::where('club_id', $alpha->id)->get();
        $this->assertGreaterThanOrEqual(15, $clubChallenges->count());
        foreach ($clubChallenges as $challenge) {
            $this->assertSame(Challenge::POOL_CLUB, $challenge->usage_pool);
            $this->assertStringStartsWith($alpha->id . '/challenges/hidden/', $challenge->hidden_image_path);
            Storage::disk('club_private')->assertExists([$challenge->hidden_image_path, $challenge->original_image_path]);
            Storage::disk('public')->assertMissing([$challenge->hidden_image_path, $challenge->original_image_path]);
            $this->assertGreaterThan(0, (float) $challenge->ball_x_ratio);
            $this->assertLessThan(1, (float) $challenge->ball_y_ratio);
        }
        $publicFiles = Storage::disk('public')->allFiles();
        foreach ($publicFiles as $file) {
            $this->assertStringStartsWith('challenges/', $file, 'only the base demo content is on the public disk');
        }
        $this->assertSame([], array_filter(Storage::disk('club_private')->allFiles(), fn ($f) => !str_starts_with($f, $alpha->id . '/')));

        // --- packs.
        $packs = ChallengePack::where('club_id', $alpha->id)->get()->keyBy('slug');
        $this->assertCount(5, $packs);
        $this->assertSame(4, $packs['qa-training-ground']->challenges()->count());
        $this->assertSame(ChallengePack::STATUS_ARCHIVED, $packs['qa-archive-pack']->status);
        $this->assertTrue($packs['qa-mixed-pack']->challenges()->whereNull('challenges.club_id')->exists(), 'D4: mixed pack holds a public challenge');

        // --- competitions in every phase.
        $phases = ClubCompetition::where('club_id', $alpha->id)->get()->mapWithKeys(fn (ClubCompetition $c) => [$c->name => $c->phase()]);
        $this->assertSame(CompetitionPhase::Active, $phases['QA Derby Days']);
        $this->assertSame(CompetitionPhase::Upcoming, $phases['QA Cup Final']);
        $this->assertSame(CompetitionPhase::Draft, $phases['QA Winter League (draft)']);
        $this->assertSame(CompetitionPhase::Finalized, $phases['QA Autumn Classic']);
        $this->assertSame(CompetitionPhase::Cancelled, $phases['QA Friendly (cancelled)']);

        $derby = ClubCompetition::where('name', 'QA Derby Days')->first();
        $this->assertSame('multiple_attempts', $this->enumValue($derby->replay_mode));
        $this->assertSame(3, (int) $derby->max_attempts);
        $this->assertSame('blocked', $this->enumValue($derby->normal_play_policy));
        $this->assertNotNull($derby->rules_locked_at);
        $ranked = app(ClubStandingsService::class)->ranked($derby);
        $this->assertGreaterThanOrEqual(7, $ranked->count());
        $ranks = $ranked->pluck('rank')->all();
        $this->assertSame(1, $ranks[0]);
        $this->assertSame([2, 2], array_slice($ranks, 1, 2), 'tie → shared rank 2');
        $this->assertSame(4, $ranks[3]);
        $this->assertSame(3, ClubCompetitionAttempt::where('club_competition_id', $derby->id)
            ->where('user_id', User::where('username', 'qa_player_05')->value('id'))->count(), 'attempt limit reached');
        $this->assertSame(1, ClubCompetitionAttempt::where('club_competition_id', $derby->id)->where('status', 'active')->count(), 'one attempt in progress');

        $autumn = ClubCompetition::where('name', 'QA Autumn Classic')->first();
        $finishes = ClubCompetitionFinish::where('club_competition_id', $autumn->id)->orderBy('placement')->get();
        $this->assertGreaterThanOrEqual(5, $finishes->count());
        $this->assertSame([1, 1, 3], $finishes->take(3)->pluck('placement')->map(fn ($p) => (int) $p)->all(), 'shared 1st placement');
        $this->assertTrue($autumn->ends_at->isPast());
        $this->assertNotNull($autumn->notified_finalized_at);

        // --- normal play: club all-time leaderboard is non-empty.
        $this->assertGreaterThanOrEqual(10, PackAttempt::where('challenge_pack_id', $packs['qa-training-ground']->id)->where('status', 'completed')->count());
        $this->assertSame(1, PackAttempt::where('challenge_pack_id', $packs['qa-training-ground']->id)->where('status', 'active')->count());

        // --- photos in every status; the converted one is linked to its challenge.
        $photos = ClubPhotoSubmission::where('club_id', $alpha->id)->get();
        $this->assertEqualsCanonicalizing(PhotoSubmissionStatus::values(), $photos->map(fn ($p) => $p->status->value)->all());
        $converted = $photos->first(fn ($p) => $p->status === PhotoSubmissionStatus::Converted);
        $this->assertSame($alpha->id, Challenge::findOrFail($converted->challenge_id)->club_id);
        $this->assertNotEmpty($photos->first(fn ($p) => $p->status === PhotoSubmissionStatus::Rejected)->rejection_reason);
        foreach ($photos as $photo) {
            Storage::disk('club_private')->assertExists($photo->path);
        }

        // --- reports + audit.
        $this->assertSame(1, ClubContentReport::where('status', 'open')->count());
        $this->assertSame(1, ClubContentReport::where('status', '!=', 'open')->count());
        $this->assertGreaterThan(50, ClubAuditEvent::where('club_id', $alpha->id)->count());

        // --- My Clubs: qa_many_clubs pages through ≥ 30 clubs.
        config(['ballspot.clubs.enabled' => true]);
        $manyClubs = User::where('username', 'qa_many_clubs')->first();
        $token = $manyClubs->createToken('test')->plainTextToken;
        $seen = [];
        $cursor = null;
        do {
            $page = $this->actingWithToken($token)->getJson('/api/clubs?limit=20' . ($cursor ? '&cursor=' . urlencode($cursor) : ''))->assertOk();
            $seen = array_merge($seen, array_column($page->json('data'), 'id'));
            $cursor = $page->json('meta.next_cursor');
        } while ($cursor !== null);
        $this->assertGreaterThanOrEqual(30, $page->json('meta.total_joined'));
        $this->assertGreaterThanOrEqual(30, count(array_unique($seen)));
        $this->assertCount(count($seen), array_unique($seen));
        $search = $this->actingWithToken($token)->getJson('/api/clubs?q=Sevilla')->assertOk();
        $this->assertSame(['QA Club 28'], array_column($search->json('data'), 'name'));
        config(['ballspot.clubs.enabled' => false]);

        // --- second run: no duplicates, nothing deleted.
        $snapshot = $this->counts();
        $privateFiles = count(Storage::disk('club_private')->allFiles());
        $this->runSeeder();
        $this->assertSame($snapshot, $this->counts());
        $this->assertSame($privateFiles, count(Storage::disk('club_private')->allFiles()));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ helpers

    private function runSeeder(): string
    {
        Artisan::call('db:seed', ['--class' => StagingNamedClubsStagingSeeder::class, '--force' => true]);

        return Artisan::output();
    }

    private function assertRefused(string $seeder, string $message): void
    {
        try {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());

            return;
        }

        $this->fail('The seeder did not refuse.');
    }

    private function assertNothingSeeded(int $usersBefore): void
    {
        $this->assertSame($usersBefore, User::count());
        $this->assertSame(0, Club::count());
        $this->assertSame(0, Sport::count());
        $this->assertSame(0, Challenge::count());
        $this->assertSame([], Storage::disk('club_private')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'users'        => User::count(),
            'clubs'        => Club::count(),
            'members'      => ClubMember::count(),
            'codes'        => ClubInviteCode::count(),
            'challenges'   => Challenge::count(),
            'packs'        => ChallengePack::count(),
            'competitions' => ClubCompetition::count(),
            'attempts'     => ClubCompetitionAttempt::count(),
            'finishes'     => ClubCompetitionFinish::count(),
            'packAttempts' => PackAttempt::count(),
            'photos'       => ClubPhotoSubmission::count(),
            'reports'      => ClubContentReport::count(),
            'audit'        => ClubAuditEvent::count(),
        ];
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    public function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
}

/** Test seam: the in-memory database gets a "staging" name. */
class StagingNamedClubsStagingSeeder extends ClubsStagingSeeder
{
    protected function databaseName(): string
    {
        return '/var/lib/ballpicker/ballpicker_staging.sqlite';
    }
}
