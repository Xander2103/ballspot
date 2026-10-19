<?php

namespace Tests\Feature\Clubs\Journey;

use App\Enums\ClubRole;
use App\Enums\PhotoSubmissionStatus;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\ClubPhotoSubmission;
use App\Models\PackAttempt;
use App\Models\Sport;
use App\Models\User;
use App\Models\XpEvent;
use App\Notifications\ClubPortalLoginCodeNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * BallPicker Clubs — one end-to-end journey through the real HTTP entry
 * points only (admin web routes, member API, club portal, scheduler command):
 *
 *  1. platform admin creates two clubs, an invite code, club challenges
 *     (private disk) and three club packs;
 *  2. a player joins with the code typed lowercase with a dash, finds the
 *     club in My Clubs (never the other club), plays the first pack (XP once)
 *     and shows on the club leaderboard;
 *  3. the admin publishes a competition on the second pack (blocked normal
 *     play), the player and two rivals play it inside the window (shared
 *     rank), the tick finalizes it → club trophy, no extra global XP;
 *  4. the club owner signs in to the portal (password + emailed code) and
 *     uploads a photo; the admin converts it into a challenge of the third
 *     pack; the player plays it through signed private media URLs;
 *  5. an outsider gets 404 on every club route; unsigned media is refused;
 *     nothing ever reaches the public catalogue or the public disk.
 *
 * The clock is frozen (UTC) and moved explicitly. Every challenge has its
 * ball at (0.5, 0.5): a guess at x = 0.5 + (100 − score) / 250 scores `score`.
 */
class ClubsEndToEndJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $owner;
    private User $player;
    private User $rival;
    private User $third;
    private User $outsider;
    private Sport $sport;

    /** @var array<int, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ballspot.clubs.enabled' => true]);
        Storage::fake('club_private');
        Storage::fake('public');
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));

        $this->sport    = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'emoji' => '⚽', 'status' => 'active']);
        $this->admin    = User::factory()->create(['is_admin' => true, 'username' => 'platform_admin']);
        $this->owner    = User::factory()->create(['username' => 'coach_kim', 'email' => 'kim@kfc-example.test']);
        $this->player   = User::factory()->create(['username' => 'lena']);
        $this->rival    = User::factory()->create(['username' => 'milan']);
        $this->third    = User::factory()->create(['username' => 'noor']);
        $this->outsider = User::factory()->create(['username' => 'stranger']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_complete_clubs_journey(): void
    {
        // 1. Platform admin sets the clubs up.
        $club  = $this->adminCreatesClub('KFC Example', 'Gent', $this->owner->username);
        $other = $this->adminCreatesClub('Antwerp Hockey', 'Antwerpen', null);
        $code  = $this->adminGeneratesInviteCode($club);

        $challenges = [];
        for ($i = 1; $i <= 4; $i++) {
            $challenges[] = $this->adminCreatesClubChallenge($club, "Derby moment {$i}");
        }
        $packA = $this->adminCreatesClubPack($club, 'Autumn Pack', [$challenges[0], $challenges[1]]);
        $packB = $this->adminCreatesClubPack($club, 'Cup Pack', [$challenges[2], $challenges[3]]);
        $packC = $this->adminCreatesClubPack($club, 'Photo Pack', []);
        $this->assertNoPublicExposure([$packA, $packB, $packC]);

        // 2. A player joins and plays a club pack.
        $this->playerJoinsWithCode($club, $code);
        $this->playerFindsOnlyTheirClub($club, $other);
        $this->playerOpensClubDetail($club);
        $this->playerPlaysClubPackOnce($club, $packA);
        $this->clubLeaderboardShowsPlayer($club);

        // 3. Competition: publish → reserved → live → shared ranks → finalize.
        foreach ([$this->rival, $this->third] as $member) {
            $this->joinWith($member, $code);
        }
        $competition = $this->adminCreatesAndPublishesCompetition($club, $packB);
        $this->normalPlayIsReserved($club, $packB);

        Carbon::setTestNow($competition->starts_at->copy());
        $this->playersCompeteWithSharedRanks($club, $competition, $packB);
        $this->competitionFinalizesWithClubTrophies($club, $competition);

        // 4. Club portal photo → admin conversion → playable via signed media.
        $submission = $this->clubOwnerUploadsPhotoViaPortal($club);
        $this->photoAppearsInAdminQueue($submission);
        $converted = $this->adminConvertsPhotoIntoPack($submission, $packC);
        $this->playerPlaysConvertedPhotoThroughSignedUrls($club, $packC, $converted);

        // 5. Isolation.
        $this->outsiderGets404Everywhere($club, $competition, $packA);
        $this->assertNoPublicExposure([$packA, $packB, $packC]);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'club media never reaches the public disk');
    }

    // ------------------------------------------------------------ 1. admin setup

    private function adminCreatesClub(string $name, string $city, ?string $owner): Club
    {
        $this->asAdmin()->get('/admin/clubs/create')->assertOk();

        $response = $this->asAdmin()->post('/admin/clubs', array_filter([
            'name'          => $name,
            'club_type'     => 'sports_club',
            'sport_id'      => $this->sport->id,
            'city'          => $city,
            'country_code'  => 'be',
            'timezone'      => 'Europe/Brussels',
            'contact_email' => 'Secretary@Example.test',
            'owner'         => $owner,
            'join_enabled'  => '1',
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors();

        $club = Club::where('name', $name)->sole();
        $response->assertRedirect(route('admin.clubs.show', $club));
        $this->assertSame('active', $club->status->value);
        $this->assertSame('BE', $club->country_code);
        $this->assertSame('secretary@example.test', $club->contact_email);
        $this->assertSame(26, strlen($club->public_id));

        if ($owner !== null) {
            $this->assertSame(ClubRole::Owner, ClubMember::where('club_id', $club->id)->where('user_id', $this->owner->id)->sole()->role);
            $this->assertSame(1, $club->members_count);
        }

        $this->asAdmin()->get('/admin/clubs?q=' . urlencode($city))->assertOk()->assertSee($name);

        return $club;
    }

    /** Returns the plain code as a person would type it: lowercase, with a dash. */
    private function adminGeneratesInviteCode(Club $club): string
    {
        $this->asAdmin()->post("/admin/clubs/{$club->id}/codes", ['label' => 'Parents U15', 'grants_role' => 'member'])
            ->assertRedirect("/admin/clubs/{$club->id}?tab=codes")
            ->assertSessionHasNoErrors();

        $code = ClubInviteCode::where('club_id', $club->id)->sole();
        $this->assertStringNotContainsString($code->code, (string) session('success'), 'the full code never reaches the flash');
        $this->asAdmin()->get("/admin/clubs/{$club->id}/codes/{$code->id}/print")->assertOk();

        $typed = strtolower(implode('-', str_split($code->code, 4)));
        $this->assertMatchesRegularExpression('/^[a-z0-9]{4}-[a-z0-9]{4}$/', $typed);

        return $typed;
    }

    private function adminCreatesClubChallenge(Club $club, string $title): Challenge
    {
        $this->asAdmin()->post('/admin/challenges', [
            'title'          => $title,
            'difficulty'     => 'medium',
            'status'         => 'active',
            'sport_id'       => $this->sport->id,
            'ball_x_ratio'   => '0,5',
            'ball_y_ratio'   => '0.5',
            'hidden_image'   => $this->jpeg(crc32($title) % 50000, 640, 480, $title . '-hidden.jpg'),
            'original_image' => $this->jpeg((crc32($title) % 50000) + 1, 640, 480, $title . '-original.jpg'),
            'club_id'        => $club->id,
        ])->assertRedirect('/admin/challenges?club=' . $club->id)->assertSessionHasNoErrors();

        $challenge = Challenge::where('title', $title)->sole();
        $this->assertSame($club->id, $challenge->club_id);
        $this->assertSame(Challenge::POOL_CLUB, $challenge->usage_pool);
        $this->assertStringStartsWith($club->id . '/challenges/hidden/', $challenge->hidden_image_path);
        $this->assertStringStartsWith($club->id . '/challenges/original/', $challenge->original_image_path);
        Storage::disk('club_private')->assertExists([$challenge->hidden_image_path, $challenge->original_image_path]);
        $this->assertSame([], Storage::disk('public')->allFiles());

        return $challenge;
    }

    /** @param list<Challenge> $challenges */
    private function adminCreatesClubPack(Club $club, string $name, array $challenges): ChallengePack
    {
        $this->asAdmin()->post('/admin/packs', [
            'name' => $name, 'status' => 'active', 'visibility' => 'public', 'sport_id' => $this->sport->id, 'club_id' => $club->id,
        ])->assertSessionHasNoErrors();
        $pack = ChallengePack::where('name', $name)->sole();
        $this->assertSame($club->id, $pack->club_id);

        if ($challenges !== []) {
            $this->asAdmin()->put("/admin/packs/{$pack->id}", [
                'name' => $name, 'status' => 'active', 'visibility' => 'public', 'sport_id' => $this->sport->id,
                'challenges' => array_map(fn (Challenge $c) => $c->id, $challenges),
            ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        }
        $this->assertSame(count($challenges), $pack->challenges()->count());

        return $pack->fresh();
    }

    // ------------------------------------------------------------ 2. player

    private function playerJoinsWithCode(Club $club, string $typedCode): void
    {
        $this->api($this->player)->postJson('/api/clubs/join', ['code' => '  ' . $typedCode . ' '])->assertOk()
            ->assertJsonPath('joined', true)
            ->assertJsonPath('data.id', $club->public_id)
            ->assertJsonPath('data.role', 'member');

        // Idempotent for an active member.
        $this->api($this->player)->postJson('/api/clubs/join', ['code' => $typedCode])->assertOk()->assertJsonPath('joined', false);

        $this->assertSame(2, $club->fresh()->members_count);
        $this->assertSame(1, ClubInviteCode::where('club_id', $club->id)->sole()->uses_count);
    }

    private function joinWith(User $user, string $typedCode): void
    {
        $this->api($user)->postJson('/api/clubs/join', ['code' => $typedCode])->assertOk()->assertJsonPath('joined', true);
    }

    private function playerFindsOnlyTheirClub(Club $club, Club $other): void
    {
        $all = $this->api($this->player)->getJson('/api/clubs')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $club->public_id)
            ->assertJsonPath('meta.total_joined', 1);
        $this->assertStringNotContainsString($other->public_id, $all->getContent());
        $this->assertStringNotContainsString('Antwerp Hockey', $all->getContent());

        $this->api($this->player)->getJson('/api/clubs?q=kfc')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'KFC Example')->assertJsonPath('meta.query', 'kfc');

        // Searching for the club they did not join never finds it.
        $this->api($this->player)->getJson('/api/clubs?q=' . urlencode('Antwerp'))->assertOk()->assertJsonCount(0, 'data');
        $this->api($this->player)->getJson('/api/clubs/' . $other->public_id)->assertNotFound()->assertJsonPath('code', 'club_not_found');
    }

    private function playerOpensClubDetail(Club $club): void
    {
        $this->api($this->player)->getJson('/api/clubs/' . $club->public_id)->assertOk()
            ->assertJsonPath('data.id', $club->public_id)
            ->assertJsonPath('data.name', 'KFC Example')
            ->assertJsonPath('data.city', 'Gent')
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.members_count', 2)
            ->assertJsonPath('data.packs_count', 3);

        $packs = $this->api($this->player)->getJson('/api/clubs/' . $club->public_id . '/packs')->assertOk();
        $this->assertEqualsCanonicalizing(['Autumn Pack', 'Cup Pack', 'Photo Pack'], array_column($packs->json('data'), 'name'));
    }

    private function playerPlaysClubPackOnce(Club $club, ChallengePack $pack): void
    {
        $start = $this->api($this->player)->postJson($this->clubPackPath($club, $pack, '/start'))->assertOk()
            ->assertJsonPath('attempt.status', 'active')
            ->assertJsonPath('attempt.total_challenges', 2);
        $attemptId = (int) $start->json('attempt.id');
        $this->assertSignedPrivateUrl((string) $start->json('challenge.hidden_image_url'));

        $this->guessNormal($attemptId, 0.5)->assertOk()->assertJsonPath('pack_completed', false);
        $this->guessNormal($attemptId, 0.5)->assertOk()
            ->assertJsonPath('pack_completed', true)
            ->assertJsonPath('final_score', 200)
            ->assertJsonPath('completion_xp', (int) config('ballspot.xp.pack_completion', 250));

        $xp = $this->xp($this->player);
        $this->assertSame(200 + (int) config('ballspot.xp.pack_completion', 250), $xp);

        // XP once: a replay is refused and earns nothing.
        $this->api($this->player)->postJson($this->clubPackPath($club, $pack, '/start'))->assertStatus(409);
        $this->assertSame($xp, $this->xp($this->player));
        $this->assertSame(1, PackAttempt::where('user_id', $this->player->id)->where('challenge_pack_id', $pack->id)->count());
    }

    private function clubLeaderboardShowsPlayer(Club $club): void
    {
        $board = $this->api($this->player)->getJson('/api/clubs/' . $club->public_id . '/leaderboard')->assertOk()
            ->assertJsonPath('meta.current_user_rank', 1);
        $this->assertSame('lena', $board->json('data.0.username'));

        $this->api($this->player)->getJson('/api/clubs/' . $club->public_id)->assertOk()
            ->assertJsonPath('data.leaderboard_preview.me.rank', 1)
            ->assertJsonPath('data.leaderboard_preview.top.0.username', 'lena');
    }

    // ------------------------------------------------------------ 3. competition

    private function adminCreatesAndPublishesCompetition(Club $club, ChallengePack $pack): ClubCompetition
    {
        $base = "/admin/clubs/{$club->id}/competitions";

        // The editor defaults: one attempt per pack, normal play blocked.
        $create = $this->asAdmin()->get($base . '/create')->assertOk();
        $this->assertMatchesRegularExpression('/value="single_attempt"[^>]*checked/', $create->getContent());
        $create->assertSee('Cup Pack');

        $this->asAdmin()->post($base, [
            'name'               => 'Autumn Cup',
            'description'        => 'Derby week',
            'starts_at'          => '2026-11-11T10:00', // club time (Brussels, UTC+1)
            'ends_at'            => '2026-11-13T22:00',
            'replay_mode'        => 'single_attempt',
            'normal_play_policy' => 'blocked',
            'pack_ids_present'   => 1,
            'pack_ids'           => [$pack->id],
        ])->assertSessionHasNoErrors();

        $competition = ClubCompetition::where('club_id', $club->id)->sole();
        $this->assertSame('draft', $competition->state->value);
        $this->assertSame('single_attempt', $competition->replay_mode->value);
        $this->assertSame('blocked', $competition->normal_play_policy->value);
        $this->assertSame('2026-11-11 09:00:00', $competition->starts_at->utc()->format('Y-m-d H:i:s'));

        // Drafts are invisible to members.
        $this->api($this->player)->getJson('/api/clubs/' . $club->public_id . '/competitions?phase=upcoming')->assertOk()->assertJsonCount(0, 'data');

        $this->asAdmin()->post("{$base}/{$competition->id}/publish", ['version' => $competition->version])
            ->assertSessionHas('success')->assertSessionMissing('error');
        $competition->refresh();
        $this->assertSame('published', $competition->state->value);
        $this->assertNull($competition->rules_locked_at);

        $this->api($this->player)->getJson('/api/clubs/' . $club->public_id . '/competitions?phase=upcoming')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $competition->public_id);

        return $competition;
    }

    private function normalPlayIsReserved(Club $club, ChallengePack $pack): void
    {
        $this->api($this->player)->postJson($this->clubPackPath($club, $pack, '/start'))->assertStatus(409)
            ->assertJsonPath('code', 'pack_reserved_for_competition');
        $this->assertSame(0, PackAttempt::where('challenge_pack_id', $pack->id)->count());

        $this->api($this->player)->getJson($this->clubPackPath($club, $pack))->assertOk()
            ->assertJsonPath('data.reserved_for_competition', true)
            ->assertJsonPath('data.challenges', []);

        // Not startable before the window either.
        $this->api($this->player)->postJson($this->competitionPath($club, ClubCompetition::where('club_id', $club->id)->sole(), '/packs/' . $pack->slug . '/attempts'), ['attempt_number' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'competition_not_started');
    }

    private function playersCompeteWithSharedRanks(Club $club, ClubCompetition $competition, ChallengePack $pack): void
    {
        $xpBefore = $this->xp($this->player);

        // The player plays both challenges (90 + 80) in the same frozen second.
        $mine = $this->startCompetitionAttempt($this->player, $club, $competition, $pack);
        $this->guessCompetition($this->player, $mine, 90)->assertOk()->assertJsonPath('pack_completed', false);
        $this->guessCompetition($this->player, $mine, 80)->assertOk()->assertJsonPath('pack_completed', true)->assertJsonPath('final_score', 170);
        $this->assertSame('completed', ClubCompetitionAttempt::findOrFail($mine)->status->value);
        $this->assertNotNull($competition->fresh()->rules_locked_at, 'rules lock once the window opened');

        // Its first run of the pack is the rewarded run (guess + completion XP).
        $this->assertGreaterThan($xpBefore, $this->xp($this->player));

        $this->api($this->player)->getJson($this->competitionPath($club, $competition, '/leaderboard'))->assertOk()
            ->assertJsonPath('data.0.username', 'lena')->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('meta.current_user_rank', 1)->assertJsonPath('meta.total_players', 1)
            ->assertJsonPath('meta.phase', 'active');
        $this->api($this->player)->getJson('/api/clubs/' . $club->public_id)->assertOk()
            ->assertJsonPath('data.competitions_preview.active.0.id', $competition->public_id)
            ->assertJsonPath('data.competitions_preview.active.0.me.rank', 1);

        // Single attempt: a second one is refused.
        $this->api($this->player)->postJson($this->competitionPath($club, $competition, '/packs/' . $pack->slug . '/attempts'), ['attempt_number' => 2])
            ->assertStatus(422)->assertJsonPath('code', 'competition_attempt_limit');

        // Milan ties exactly (same total, same second) → shared rank; Noor is behind.
        $theirs = $this->startCompetitionAttempt($this->rival, $club, $competition, $pack);
        $this->guessCompetition($this->rival, $theirs, 70)->assertOk();
        $this->guessCompetition($this->rival, $theirs, 100)->assertOk()->assertJsonPath('final_score', 170);

        $this->tick(60);
        $noor = $this->startCompetitionAttempt($this->third, $club, $competition, $pack);
        $this->guessCompetition($this->third, $noor, 50)->assertOk();
        $this->guessCompetition($this->third, $noor, 50)->assertOk();

        $board = $this->api($this->third)->getJson($this->competitionPath($club, $competition, '/leaderboard'))->assertOk()
            ->assertJsonPath('meta.total_players', 3)->assertJsonPath('meta.current_user_rank', 3);
        $this->assertSame([1, 1, 3], array_column($board->json('data'), 'rank'));
        $this->assertEqualsCanonicalizing(['lena', 'milan'], array_slice(array_column($board->json('data'), 'username'), 0, 2));
        $this->assertSame('noor', $board->json('data.2.username'));

        $this->api($this->rival)->getJson('/api/clubs/' . $club->public_id)->assertOk()
            ->assertJsonPath('data.competitions_preview.active.0.me.rank', 1);
    }

    private function competitionFinalizesWithClubTrophies(Club $club, ClubCompetition $competition): void
    {
        $xp     = $this->xp($this->player);
        $badges = $this->player->badges()->count();

        // A tick inside the window changes nothing.
        $this->assertSame(0, Artisan::call('ballspot:clubs:competition-tick'));
        $this->assertSame('published', $competition->fresh()->state->value);

        Carbon::setTestNow($competition->ends_at->copy()->addMinute());
        $this->assertSame(0, Artisan::call('ballspot:clubs:competition-tick'));

        $competition->refresh();
        $this->assertSame('finalized', $competition->state->value);
        $this->assertNotNull($competition->finalized_at);
        $this->assertSame(
            [$this->player->id => 1, $this->rival->id => 1, $this->third->id => 3],
            ClubCompetitionFinish::where('club_competition_id', $competition->id)->pluck('placement', 'user_id')->sortKeys()->all(),
        );

        // Idempotent.
        $this->assertSame(0, Artisan::call('ballspot:clubs:competition-tick'));
        $this->assertSame(3, ClubCompetitionFinish::where('club_competition_id', $competition->id)->count());

        $finishes = $this->api($this->player)->getJson('/api/me/club-finishes')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.placement', 1);
        $this->assertStringContainsString('Autumn Cup', $finishes->getContent());
        $this->assertStringContainsString($club->public_id, $finishes->getContent());

        // A placement is a club trophy only: no global XP, no global badge.
        $this->assertSame($xp, $this->xp($this->player));
        $this->assertSame($badges, $this->player->badges()->count());

        $this->api($this->player)->getJson($this->competitionPath($club, $competition, '/leaderboard'))->assertOk()
            ->assertJsonPath('meta.phase', 'finalized');
    }

    // ------------------------------------------------------------ 4. portal → photo → challenge

    private function clubOwnerUploadsPhotoViaPortal(Club $club): ClubPhotoSubmission
    {
        $this->signOutEverywhere();

        $this->get('/club/login')->assertOk();
        $this->from('/club/login')->post('/club/login', ['email' => 'kim@kfc-example.test', 'password' => 'password'])
            ->assertRedirect('/club/verify');
        $this->assertGuest('web');

        $code = null;
        Notification::assertSentTo($this->owner, ClubPortalLoginCodeNotification::class, function (ClubPortalLoginCodeNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        $this->get('/club/verify')->assertOk();
        $this->post('/club/verify', ['code' => (string) $code])->assertRedirect(route('club-portal.home'));
        $this->assertAuthenticatedAs($this->owner, 'web');

        $this->get('/club')->assertRedirect('/club/' . $club->slug);
        $this->get('/club/' . $club->slug)->assertOk()->assertSee('KFC Example');

        // Rights confirmation is mandatory.
        $this->from('/club/' . $club->slug . '/photos')
            ->post('/club/' . $club->slug . '/photos', ['photos' => [$this->jpeg(7001, 800, 600)], 'club_note' => 'derby goal'])
            ->assertSessionHasErrors('rights_confirmed');
        $this->assertSame(0, ClubPhotoSubmission::count());

        $this->from('/club/' . $club->slug . '/photos')
            ->post('/club/' . $club->slug . '/photos', [
                'photos' => [$this->jpeg(7002, 800, 600, 'derby.jpg')], 'club_note' => 'derby goal min 89', 'rights_confirmed' => '1',
            ])->assertRedirect('/club/' . $club->slug . '/photos')->assertSessionHasNoErrors();

        $submission = ClubPhotoSubmission::where('club_id', $club->id)->sole();
        $this->assertSame(ClubPhotoSubmission::SOURCE_CLUB_UPLOAD, $submission->source);
        $this->assertSame(PhotoSubmissionStatus::Pending, $submission->status);
        $this->assertSame($this->owner->id, $submission->uploaded_by_user_id);
        $this->assertNotNull($submission->rights_confirmed_at);
        $this->assertStringStartsWith($club->id . '/photos/', $submission->path);
        Storage::disk('club_private')->assertExists($submission->path);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->get('/club/' . $club->slug . '/photos')->assertOk()->assertSee('derby goal min 89');

        return $submission;
    }

    private function photoAppearsInAdminQueue(ClubPhotoSubmission $submission): void
    {
        $this->signOutEverywhere();

        $this->asAdmin()->get('/admin/club-photos')->assertOk()
            ->assertSee('data-submission="' . $submission->id . '"', false)
            ->assertSee('KFC Example')
            ->assertSee('/media/clubs/', false)
            ->assertDontSee('/storage/' . $submission->path, false);

        $this->asAdmin()->get('/admin/club-photos/' . $submission->id . '/prepare')->assertOk()->assertSee('Photo Pack');
    }

    private function adminConvertsPhotoIntoPack(ClubPhotoSubmission $submission, ChallengePack $pack): Challenge
    {
        // Ball-removed image + ball position, appended to the (unlocked) Photo Pack.
        $this->asAdmin()->post('/admin/club-photos/' . $submission->id . '/convert', [
            'hidden_image' => $this->jpeg(7003, 800, 600, 'ball-removed.jpg'),
            'title'        => 'Derby goal 89\'',
            'difficulty'   => 'hard',
            'status'       => 'active',
            'ball_x_ratio' => '0,5',
            'ball_y_ratio' => '0.5',
            'pack_id'      => $pack->id,
        ])->assertRedirect('/admin/club-photos/' . $submission->id . '/prepare')
          ->assertSessionHasNoErrors()->assertSessionMissing('error');

        $submission->refresh();
        $this->assertSame(PhotoSubmissionStatus::Converted, $submission->status);
        $challenge = Challenge::findOrFail($submission->challenge_id);
        $this->assertSame($submission->club_id, $challenge->club_id);
        $this->assertSame(Challenge::POOL_CLUB, $challenge->usage_pool);
        Storage::disk('club_private')->assertExists([$challenge->hidden_image_path, $challenge->original_image_path, $submission->path]);
        $this->assertSame([$challenge->id], $pack->challenges()->pluck('challenges.id')->all());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->asAdmin()->get('/admin/club-photos/' . $submission->id . '/prepare')->assertOk()->assertSee('Converted.');

        return $challenge;
    }

    private function playerPlaysConvertedPhotoThroughSignedUrls(Club $club, ChallengePack $pack, Challenge $challenge): void
    {
        $start = $this->api($this->player)->postJson($this->clubPackPath($club, $pack, '/start'))->assertOk()
            ->assertJsonPath('attempt.total_challenges', 1)
            ->assertJsonPath('challenge.id', $challenge->id);

        $hiddenUrl = (string) $start->json('challenge.hidden_image_url');
        $this->assertSignedPrivateUrl($hiddenUrl);
        $this->assertStringContainsString(basename($challenge->hidden_image_path), urldecode($hiddenUrl));

        $path = (string) parse_url($hiddenUrl, PHP_URL_PATH);
        $this->get($path . '?' . parse_url($hiddenUrl, PHP_URL_QUERY))->assertOk();
        $this->get($path)->assertForbidden();
        $this->assertNotSame(200, $this->get('/storage/' . $challenge->hidden_image_path)->getStatusCode());

        $guess = $this->guessNormal((int) $start->json('attempt.id'), 0.5)->assertOk()->assertJsonPath('pack_completed', true);
        // The reveal (the original photo copy) is a signed private URL too.
        $this->assertSignedPrivateUrl((string) $guess->json('result.reveal_image_url'));
        $this->assertStringContainsString(basename($challenge->original_image_path), urldecode((string) $guess->json('result.reveal_image_url')));
    }

    // ------------------------------------------------------------ 5. isolation

    private function outsiderGets404Everywhere(Club $club, ClubCompetition $competition, ChallengePack $pack): void
    {
        $this->api($this->outsider)->getJson('/api/clubs')->assertOk()->assertJsonCount(0, 'data');

        $attempt = ClubCompetitionAttempt::where('user_id', $this->player->id)->firstOrFail();
        $c       = $this->competitionPath($club, $competition);
        $calls   = [
            ['GET', '/api/clubs/' . $club->public_id],
            ['GET', '/api/clubs/' . $club->public_id . '/members'],
            ['GET', '/api/clubs/' . $club->public_id . '/leaderboard'],
            ['GET', '/api/clubs/' . $club->public_id . '/packs'],
            ['GET', $this->clubPackPath($club, $pack)],
            ['GET', $this->clubPackPath($club, $pack, '/attempt')],
            ['POST', $this->clubPackPath($club, $pack, '/start')],
            ['POST', '/api/clubs/' . $club->public_id . '/leave'],
            ['GET', '/api/clubs/' . $club->public_id . '/competitions'],
            ['GET', $c],
            ['GET', $c . '/leaderboard'],
            ['GET', $c . '/me/attempts'],
            ['POST', $c . '/packs/' . $pack->slug . '/attempts'],
            ['GET', '/api/club-competition-attempts/' . $attempt->id],
        ];
        foreach ($calls as [$method, $uri]) {
            $res = $this->api($this->outsider)->json($method, $uri, $method === 'POST' ? ['attempt_number' => 1] : []);
            $this->assertSame(404, $res->getStatusCode(), "{$method} {$uri} must be 404 for an outsider");
            $this->assertStringNotContainsString('KFC Example', (string) $res->getContent(), "{$method} {$uri} leaks the club name");
        }

        $this->api($this->outsider)->postJson('/api/clubs/join', ['code' => 'zzzz-zzzz'])->assertStatus(422)->assertJsonPath('code', 'club_code_invalid');
        $this->assertSame(0, ClubMember::where('user_id', $this->outsider->id)->count());

        // Portal: without a verified portal sign-in, club pages send to the login.
        $this->signOutEverywhere();
        $this->get('/club/' . $club->slug)->assertRedirect('/club/login');
    }

    /** @param list<ChallengePack> $packs */
    private function assertNoPublicExposure(array $packs): void
    {
        $catalogue = $this->api($this->outsider)->getJson('/api/packs')->assertOk();
        foreach ($packs as $pack) {
            $this->assertStringNotContainsString($pack->slug, $catalogue->getContent(), "club pack {$pack->slug} in the public catalogue");
            $this->api($this->outsider)->getJson('/api/packs/' . $pack->slug)->assertNotFound();
            $this->api($this->player)->postJson('/api/packs/' . $pack->slug . '/start')->assertNotFound();
        }
    }

    // ------------------------------------------------------------ helpers

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin);
    }

    /** Drop any web session (portal / admin) so the next request starts clean. */
    private function signOutEverywhere(): void
    {
        $this->flushSession();
        $this->withoutToken();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    /** Member API as the given user (Sanctum token; never a leftover web session). */
    private function api(User $user): static
    {
        $this->tokens[$user->id] ??= $user->createToken('journey')->plainTextToken;
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$user->id]);
    }

    private function clubPackPath(Club $club, ChallengePack $pack, string $suffix = ''): string
    {
        return '/api/clubs/' . $club->public_id . '/packs/' . $pack->slug . $suffix;
    }

    private function competitionPath(Club $club, ClubCompetition $competition, string $suffix = ''): string
    {
        return '/api/clubs/' . $club->public_id . '/competitions/' . $competition->public_id . $suffix;
    }

    private function guessNormal(int $attemptId, float $x): TestResponse
    {
        $attempt = PackAttempt::findOrFail($attemptId);

        return $this->api(User::findOrFail($attempt->user_id))->postJson("/api/pack-attempts/{$attemptId}/guess", [
            'challenge_id' => $attempt->challengeIds()[$attempt->current_index],
            'guessed_x'    => $x,
            'guessed_y'    => 0.5,
        ]);
    }

    private function startCompetitionAttempt(User $user, Club $club, ClubCompetition $competition, ChallengePack $pack): int
    {
        return (int) $this->api($user)
            ->postJson($this->competitionPath($club, $competition, '/packs/' . $pack->slug . '/attempts'), ['attempt_number' => 1])
            ->assertCreated()
            ->assertJsonPath('attempt.competition_id', $competition->public_id)
            ->json('attempt.id');
    }

    private function guessCompetition(User $user, int $attemptId, int $score): TestResponse
    {
        $attempt = ClubCompetitionAttempt::findOrFail($attemptId);

        return $this->api($user)->postJson("/api/club-competition-attempts/{$attemptId}/guess", [
            'challenge_id' => $attempt->challengeIds()[$attempt->current_index],
            'guessed_x'    => 0.5 + (100 - $score) / 250,
            'guessed_y'    => 0.5,
        ]);
    }

    private function tick(int $seconds): void
    {
        Carbon::setTestNow(now()->addSeconds($seconds));
    }

    private function xp(User $user): int
    {
        return (int) XpEvent::where('user_id', $user->id)->sum('amount');
    }

    private function assertSignedPrivateUrl(string $url): void
    {
        $this->assertStringContainsString('/media/clubs/', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('/storage/', $url);
    }

    /** A real JPEG (GD-decodable); the seed changes the pixels. */
    private function jpeg(int $seed, int $w = 640, int $h = 480, string $name = 'photo.jpg'): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, $seed % 256, (int) ($seed / 256) % 256, 90));
        imagefilledrectangle($img, 10, 10, 40, 40, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagejpeg($img, null, 90);
        $path = tempnam(sys_get_temp_dir(), 'journey') . '.jpg';
        file_put_contents($path, (string) ob_get_clean());

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }
}
