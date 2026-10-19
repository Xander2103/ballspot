<?php

namespace Database\Seeders;

use App\Contracts\PackAccessGate;
use App\Enums\ClubRole;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengeCategory;
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
use App\Services\ChallengeCreator;
use App\Services\Clubs\ClubAuditLogger;
use App\Services\Clubs\ClubCompetitionCloseService;
use App\Services\Clubs\ClubCompetitionPlayService;
use App\Services\Clubs\ClubCompetitionService;
use App\Services\Clubs\ClubContentReportService;
use App\Services\Clubs\ClubContentScope;
use App\Services\Clubs\ClubInviteCodeService;
use App\Services\Clubs\ClubMembershipService;
use App\Services\Clubs\ClubPhotoReviewService;
use App\Services\Clubs\ClubService;
use App\Services\ClubPortal\ClubPortalMemberRules;
use App\Services\PackPlayService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\Staging\QaPitchImage;
use Database\Seeders\Staging\StagingSeedGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * STAGING-ONLY QA data for BallPicker Clubs (docs/clubs-staging-seed.md).
 *
 *   APP_ENV=staging BALLPICKER_STAGING_SEED_CONFIRM=yes \
 *   BALLPICKER_STAGING_SEED_PASSWORD='…' php artisan db:seed --class=ClubsStagingSeeder
 *
 * Refuses (throws, writes nothing) unless every StagingSeedGuard check holds.
 * BALLPICKER_STAGING_SEED_DRY_RUN=yes prints the plan only.
 *
 * Everything goes through the real services (ClubService, memberships via
 * invite codes, ChallengeCreator, ClubCompetitionService / PlayService /
 * CloseService, ClubPhotoReviewService, ClubContentReportService,
 * PackPlayService), so audit events, counters, standings and finishes are the
 * real thing. Club packs have no service: they are created like
 * Admin\ChallengePackController::store (row + pivot + `pack.created` audit).
 *
 * The history is told on a moving clock (Carbon test-now, restored at the
 * end): accounts 35 days ago, clubs 30 days ago, a competition played and
 * finalized 2 weeks ago, normal play 5 days ago, the live competition played
 * yesterday. While it runs: pushes off, Mail/Notification/Http faked, the
 * Clubs flag forced on in memory only. One DB transaction: a failure leaves
 * no partial rows. Idempotent: every part is keyed (username, slug, label,
 * title, name, note) and skipped when present; nothing is ever deleted.
 */
class ClubsStagingSeeder extends Seeder
{
    public const DOMAIN = StagingSeedGuard::QA_DOMAIN;
    private const LANGUAGES = ['en', 'nl', 'fr', 'de', 'es'];

    private const SMALL_CLUB_CITIES = [
        ['Antwerpen', 'BE', 'Europe/Brussels'], ['Brugge', 'BE', 'Europe/Brussels'], ['Leuven', 'BE', 'Europe/Brussels'],
        ['Mechelen', 'BE', 'Europe/Brussels'], ['Hasselt', 'BE', 'Europe/Brussels'], ['Liège', 'BE', 'Europe/Brussels'],
        ['Namur', 'BE', 'Europe/Brussels'], ['Charleroi', 'BE', 'Europe/Brussels'], ['Kortrijk', 'BE', 'Europe/Brussels'],
        ['Oostende', 'BE', 'Europe/Brussels'], ['Amsterdam', 'NL', 'Europe/Amsterdam'], ['Rotterdam', 'NL', 'Europe/Amsterdam'],
        ['Utrecht', 'NL', 'Europe/Amsterdam'], ['Eindhoven', 'NL', 'Europe/Amsterdam'], ['Groningen', 'NL', 'Europe/Amsterdam'],
        ['Lille', 'FR', 'Europe/Paris'], ['Paris', 'FR', 'Europe/Paris'], ['Lyon', 'FR', 'Europe/Paris'],
        ['Marseille', 'FR', 'Europe/Paris'], ['Bordeaux', 'FR', 'Europe/Paris'], ['Köln', 'DE', 'Europe/Berlin'],
        ['Berlin', 'DE', 'Europe/Berlin'], ['München', 'DE', 'Europe/Berlin'], ['Hamburg', 'DE', 'Europe/Berlin'],
        ['Düsseldorf', 'DE', 'Europe/Berlin'], ['Madrid', 'ES', 'Europe/Madrid'], ['Barcelona', 'ES', 'Europe/Madrid'],
        ['Sevilla', 'ES', 'Europe/Madrid'], ['Valencia', 'ES', 'Europe/Madrid'], ['Bilbao', 'ES', 'Europe/Madrid'],
    ];

    private const CLUB_TYPES = ['sports_club', 'school', 'company', 'community', 'other'];

    /** Number of synthetic Alpha challenges (≥ 14). */
    private const ALPHA_CHALLENGES = 15;

    /** @var array<string, User> */
    private array $users = [];
    private CarbonImmutable $realNow;
    private QaPitchImage $images;

    private ClubService $clubs;
    private ClubMembershipService $memberships;
    private ClubInviteCodeService $codes;
    private ClubAuditLogger $audit;
    private ChallengeCreator $creator;
    private ClubCompetitionService $competitions;
    private ClubCompetitionPlayService $competitionPlay;
    private ClubCompetitionCloseService $close;
    private ClubPhotoReviewService $photos;
    private ClubContentReportService $reports;
    private PackPlayService $packPlay;
    private PackAccessGate $packAccess;

    public function run(): void
    {
        $failures = (new StagingSeedGuard())->failures($this->databaseName());
        if ($failures !== []) {
            $this->error('ClubsStagingSeeder REFUSED — nothing was written:');
            foreach ($failures as $failure) {
                $this->error('  ✗ ' . $failure);
            }

            throw new \RuntimeException('ClubsStagingSeeder refused: ' . implode(' ', $failures));
        }

        if (StagingSeedGuard::dryRun()) {
            $this->line('ClubsStagingSeeder DRY RUN — nothing is written. Plan:');
            foreach (self::plan() as $label => $count) {
                $this->line(sprintf('  %-44s %s', $label, $count));
            }

            return;
        }

        $this->seed();
    }

    /**
     * The default connection's database name (sqlite: the file path). The
     * only seam the tests override, because their in-memory database has no
     * name — there is deliberately no env/config switch for it.
     */
    protected function databaseName(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    /** @return array<string, int|string> */
    public static function plan(): array
    {
        return [
            'QA accounts (@' . self::DOMAIN . ')'          => count(self::accounts()),
            'Clubs (Alpha, Beta, Gamma, Delta + small)'     => 4 + count(self::SMALL_CLUB_CITIES),
            'Invite codes (5 on Alpha, 1 on Delta)'         => 6,
            'Alpha club challenges (GD, private disk)'      => self::ALPHA_CHALLENGES . ' + 1 converted photo',
            'Alpha club packs'                              => 5,
            'Alpha competitions (active/upcoming/draft/finalized/cancelled)' => 5,
            'Alpha photo submissions (one per status)'      => 5,
            'Content reports (open + resolved)'             => 2,
            'Base content seeders (only when missing)'      => 'Sport, ChallengeCategory, Challenge, Badge',
        ];
    }

    /** @return array<string, array{name: string, language: string, admin: bool}> username => profile */
    public static function accounts(): array
    {
        $names = ['qa_admin', 'qa_owner_alpha', 'qa_admin_alpha', 'qa_owner_beta'];
        for ($i = 1; $i <= 12; $i++) {
            $names[] = sprintf('qa_player_%02d', $i);
        }
        array_push($names, 'qa_outsider', 'qa_banned', 'qa_left', 'qa_many_clubs');

        $accounts = [];
        foreach ($names as $i => $username) {
            $accounts[$username] = [
                'name'     => ucwords(str_replace('_', ' ', str_replace('qa_', 'QA ', $username))),
                'language' => self::LANGUAGES[$i % count(self::LANGUAGES)],
                'admin'    => $username === 'qa_admin',
            ];
        }
        foreach (['nl' => 'Dutch', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish'] as $lang => $label) {
            $accounts['qa_' . $lang] = ['name' => "QA {$label} Player", 'language' => $lang, 'admin' => false];
        }

        return $accounts;
    }

    // ------------------------------------------------------------------
    // Orchestration
    // ------------------------------------------------------------------

    private function seed(): void
    {
        $previousNow   = Carbon::getTestNow();
        $previousFlags = [
            'ballspot.notifications.push_enabled' => config('ballspot.notifications.push_enabled'),
            'ballspot.clubs.enabled'              => config('ballspot.clubs.enabled'),
        ];

        // Runtime isolation: no push, no mail, no notification, no outbound HTTP.
        config(['ballspot.notifications.push_enabled' => false, 'ballspot.clubs.enabled' => true]);
        Mail::fake();
        Notification::fake();
        Http::fake();

        $this->realNow = CarbonImmutable::now();
        $this->images  = new QaPitchImage();
        $this->resolveServices();

        try {
            DB::transaction(function () {
                $this->seedBaseContent();
                $this->seedAccounts();
                $this->seedStory();
            });
        } finally {
            Carbon::setTestNow($previousNow);
            config($previousFlags);
            $this->images->cleanup();
        }

        $this->printSummary();
    }

    private function resolveServices(): void
    {
        $this->clubs           = app(ClubService::class);
        $this->memberships     = app(ClubMembershipService::class);
        $this->codes           = app(ClubInviteCodeService::class);
        $this->audit           = app(ClubAuditLogger::class);
        $this->creator         = app(ChallengeCreator::class);
        $this->competitions    = app(ClubCompetitionService::class);
        $this->competitionPlay = app(ClubCompetitionPlayService::class);
        $this->close           = app(ClubCompetitionCloseService::class);
        $this->photos          = app(ClubPhotoReviewService::class);
        $this->reports         = app(ClubContentReportService::class);
        $this->packPlay        = app(PackPlayService::class);
        $this->packAccess      = app(PackAccessGate::class);
    }

    private function seedBaseContent(): void
    {
        if (!Sport::where('slug', 'football')->exists()) {
            $this->call(SportSeeder::class);
        }
        $football = Sport::where('slug', 'football')->firstOrFail();
        if (!ChallengeCategory::where('sport_id', $football->id)->exists()) {
            $this->call(ChallengeCategorySeeder::class);
        }
        if (!Challenge::whereNull('club_id')->exists()) {
            $this->call(ChallengeSeeder::class);
        }
        if (!Badge::where('code', 'first_guess')->exists()) {
            $this->call(BadgeSeeder::class);
        }
    }

    private function seedAccounts(): void
    {
        $this->at($this->days(-35));
        $hash = null;

        foreach (self::accounts() as $username => $profile) {
            $user = User::where('username', $username)->first();
            if ($user === null) {
                $hash ??= Hash::make(StagingSeedGuard::password());
                $user = new User([
                    'name'               => $profile['name'],
                    'username'           => $username,
                    'email'              => $username . '@' . self::DOMAIN,
                    'password'           => $hash,
                    'preferred_language' => $profile['language'],
                ]);
                $user->forceFill([
                    'email_verified_at' => now(),
                    'terms_accepted_at' => now(),
                    'terms_version'     => (string) config('ballspot.legal.terms_version', '2026-08'),
                    'is_admin'          => $profile['admin'],
                ])->save();
                $this->tick(60);
            }
            $this->users[$username] = $user;
        }
    }

    /** The whole club history, oldest first. */
    private function seedStory(): void
    {
        $admin = $this->u('qa_admin');

        // Day −30: clubs and codes.
        $this->at($this->days(-30));
        $alpha = $this->ensureClub('qa-fc-alpha', [
            'name'         => 'QA FC Alpha',
            'description'  => 'Staging QA club: members, packs, competitions, photos and reports.',
            'sport_id'     => $this->sportId('football'),
            'club_type'    => 'sports_club',
            'city'         => 'Gent',
            'region'       => 'Oost-Vlaanderen',
            'country_code' => 'BE',
            'timezone'     => 'Europe/Brussels',
            'settings'     => ['member_list_visibility' => 'members'],
        ], $this->u('qa_owner_alpha'));
        $beta = $this->ensureClub('qa-beta-hockey', [
            'name' => 'QA Beta Hockey', 'description' => 'Staging QA club (suspended: read-only).', 'sport_id' => $this->sportId('hockey'),
            'club_type' => 'sports_club', 'city' => 'Antwerpen', 'country_code' => 'BE', 'timezone' => 'Europe/Brussels',
        ], $this->u('qa_owner_beta'));
        $gamma = $this->ensureClub('qa-gamma-school', [
            'name' => 'QA Gamma School', 'description' => 'Staging QA club (archived: hidden from My Clubs).', 'sport_id' => $this->sportId('football'),
            'club_type' => 'school', 'city' => 'Brugge', 'country_code' => 'BE', 'timezone' => 'Europe/Brussels',
        ], $this->u('qa_owner_beta'));
        $delta = $this->ensureClub('qa-delta-empty', [
            'name' => 'QA Delta Empty', 'description' => 'Staging QA club without owner, packs or competitions.', 'sport_id' => $this->sportId('football'),
            'club_type' => 'community', 'city' => 'Leuven', 'country_code' => 'BE', 'timezone' => 'Europe/Brussels',
        ], null);
        $small = $this->ensureSmallClubs();

        $memberCode    = $this->ensureCode($alpha, 'QA member code', ['expires_at' => $this->days(150)], $this->u('qa_owner_alpha'), ClubAuditEvent::ACTOR_CLUB_ADMIN);
        $adminCode     = $this->ensureCode($alpha, 'QA admin code', ['grants_role' => ClubRole::Admin, 'max_uses' => 5], $this->u('qa_owner_alpha'), ClubAuditEvent::ACTOR_CLUB_ADMIN);
        $exhaustedCode = $this->ensureCode($alpha, 'QA exhausted code', ['max_uses' => 2], $admin, ClubAuditEvent::ACTOR_PLATFORM_ADMIN);
        $this->ensureCode($alpha, 'QA expired code', ['expires_at' => $this->days(-23)], $admin, ClubAuditEvent::ACTOR_PLATFORM_ADMIN);
        $revoked = $this->ensureCode($alpha, 'QA revoked code', [], $admin, ClubAuditEvent::ACTOR_PLATFORM_ADMIN);
        $this->ensureCode($delta, 'QA Delta code', [], $admin, ClubAuditEvent::ACTOR_PLATFORM_ADMIN);

        // Day −28: people join.
        $this->at($this->days(-28));
        $this->join('qa_admin_alpha', $alpha, $adminCode);
        foreach (array_merge($this->players(), ['qa_de', 'qa_es', 'qa_banned', 'qa_left']) as $username) {
            $this->join($username, $alpha, $memberCode);
        }
        foreach (['qa_nl', 'qa_fr'] as $username) {
            $this->join($username, $alpha, $exhaustedCode); // max_uses 2 → exhausted afterwards
        }
        foreach (['qa_player_01', 'qa_player_02', 'qa_many_clubs'] as $username) {
            $this->memberships->add($beta, $this->u($username), ClubRole::Member, $admin, ClubAuditEvent::ACTOR_PLATFORM_ADMIN);
        }
        foreach (['qa_player_01', 'qa_many_clubs'] as $username) {
            $this->memberships->add($gamma, $this->u($username), ClubRole::Member, $admin, ClubAuditEvent::ACTOR_PLATFORM_ADMIN);
        }
        foreach ($small as $i => $club) {
            $this->memberships->add($club, $this->u('qa_many_clubs'), $i % 6 === 0 ? ClubRole::Admin : ClubRole::Member, $admin, ClubAuditEvent::ACTOR_PLATFORM_ADMIN);
            $this->tick(30);
        }

        // Day −25: the revoked code is revoked.
        $this->at($this->days(-25));
        $this->codes->revoke($revoked, $admin, ClubAuditEvent::ACTOR_PLATFORM_ADMIN);

        // Day −20: challenges and packs.
        $this->at($this->days(-20));
        $challenges = $this->ensureAlphaChallenges($alpha);
        $public = Challenge::whereNull('club_id')->where('status', 'active')->readySql()->orderBy('id')->first();
        $training = $this->ensurePack($alpha, 'qa-training-ground', 'QA Training Ground', 'Normal play: four warm-up photos.', array_slice($challenges, 0, 4), 'easy');
        $derby    = $this->ensurePack($alpha, 'qa-derby-pack', 'QA Derby Pack', 'Linked to the live competition (normal play blocked).', array_slice($challenges, 4, 4), 'medium');
        $cupFinal = $this->ensurePack($alpha, 'qa-cup-final', 'QA Cup Final', 'Linked to the upcoming competition.', array_slice($challenges, 8, 3), 'hard');
        $this->ensurePack($alpha, 'qa-archive-pack', 'QA Archive Pack', 'Archived pack (hidden from members).', array_slice($challenges, 11, 2), 'mixed', archived: true);
        $mixed    = $this->ensurePack($alpha, 'qa-mixed-pack', 'QA Mixed Pack', 'Club photos plus one BallPicker challenge (D4); used by the finalized competition.',
            array_values(array_filter([...array_slice($challenges, 13, 2), $public?->id])), 'mixed');

        // Day −17 … −10: a finished competition, finalized like the scheduler does.
        $this->seedFinalizedCompetition($alpha, $mixed);

        // Day −6: Gamma archived. Day −5: normal play of the Training Ground.
        $this->at($this->days(-6));
        $this->clubs->archive($gamma, $admin, 'QA: archived to test hidden clubs');
        $this->seedNormalPlay($alpha, $training);

        // Day −4 / −3: a ban and a leave.
        $this->at($this->days(-4));
        $this->banFromAlpha($alpha, 'qa_banned');
        $this->at($this->days(-3));
        if ($this->memberships->membership($alpha, $this->u('qa_left'))) {
            $this->memberships->leave($alpha, $this->u('qa_left'));
        }

        // Day −3 … −1 morning: the live competition (window day −2 … +12).
        $this->seedActiveCompetition($alpha, $derby);

        // Day −1 noon: Beta suspended; afternoon: photos.
        $this->at($this->days(-1)->setTime(12, 0));
        $this->clubs->suspend($beta, $admin, 'QA: suspended to test read-only mode');
        $this->seedPhotos($alpha);

        // Today (a few hours ago, so no timestamp lies in the future): upcoming,
        // cancelled and draft competitions; reports.
        $this->at($this->realNow->subHours(3));
        $this->seedFutureCompetitions($alpha, $training, $cupFinal);
        $this->seedReports($alpha);

        foreach (array_merge([$alpha, $beta, $gamma, $delta], $small) as $club) {
            $this->memberships->refreshCounters($club);
        }
    }

    // ------------------------------------------------------------------
    // Clubs, codes, members
    // ------------------------------------------------------------------

    private function ensureClub(string $slug, array $data, ?User $owner): Club
    {
        $club = Club::where('slug', $slug)->first();
        if ($club !== null) {
            return $club;
        }

        $club = $this->clubs->create($data + ['slug' => $slug, 'owner_user_id' => $owner?->id], $this->u('qa_admin'));
        $this->tick(120);

        return $club;
    }

    /** @return list<Club> QA Club 01 … 30 */
    private function ensureSmallClubs(): array
    {
        $clubs = [];
        foreach (self::SMALL_CLUB_CITIES as $i => [$city, $country, $timezone]) {
            $n = sprintf('%02d', $i + 1);
            $clubs[] = $this->ensureClub('qa-club-' . $n, [
                'name'         => 'QA Club ' . $n,
                'description'  => 'Small staging QA club for My Clubs search and pagination.',
                'sport_id'     => $this->sportId('football'),
                'club_type'    => self::CLUB_TYPES[$i % count(self::CLUB_TYPES)],
                'city'         => $city,
                'country_code' => $country,
                'timezone'     => $timezone,
            ], $this->u('qa_owner_beta'));
        }

        return $clubs;
    }

    private function ensureCode(Club $club, string $label, array $opts, User $actor, string $actorKind): ClubInviteCode
    {
        $code = ClubInviteCode::where('club_id', $club->id)->where('label', $label)->orderBy('id')->first();
        if ($code !== null) {
            return $code;
        }

        $code = $this->codes->create($club, ['label' => $label] + $opts, $actor, $actorKind);
        $this->tick(45);

        return $code;
    }

    /** Join through the real invite-code flow (skipped when the user has any membership row). */
    private function join(string $username, Club $club, ClubInviteCode $code): void
    {
        $user = $this->u($username);
        if ($this->memberships->membershipRecord($club, $user) !== null) {
            return;
        }

        $this->memberships->joinWithCode($user, $code->code, null);
        $this->tick(300);
    }

    private function banFromAlpha(Club $alpha, string $username): void
    {
        $member = $this->memberships->membership($alpha, $this->u($username));
        if ($member === null) {
            return;
        }

        $owner = $this->u('qa_owner_alpha');
        $this->memberships->actAsManager($alpha, $owner, $member, ClubPortalMemberRules::BAN, fn (ClubMember $target) => $this->memberships->ban(
            $target, $owner, ClubAuditEvent::ACTOR_CLUB_ADMIN, 'QA: banned to test the banned-member flow',
        ));
    }

    // ------------------------------------------------------------------
    // Challenges and packs
    // ------------------------------------------------------------------

    /** @return list<int> challenge ids in title order */
    private function ensureAlphaChallenges(Club $alpha): array
    {
        $difficulties = ['easy', 'medium', 'hard'];
        $ids = [];

        for ($n = 1; $n <= self::ALPHA_CHALLENGES; $n++) {
            $title = sprintf('QA Pitch %02d', $n);
            $existing = Challenge::where('club_id', $alpha->id)->where('title', $title)->first();
            if ($existing !== null) {
                $ids[] = (int) $existing->id;
                continue;
            }

            $seed = self::imageSeed('alpha-pitch-' . $n);
            $ball = QaPitchImage::ballPosition($seed);
            $paths = $this->creator->storeImages(
                $alpha,
                $this->images->upload($seed, false, 'QA STAGING ' . $title),
                $this->images->upload($seed, true, 'QA STAGING ' . $title),
            );

            try {
                $challenge = $this->creator->create([
                    'title'        => $title,
                    'difficulty'   => $difficulties[($n - 1) % 3],
                    'status'       => 'active',
                    'ball_x_ratio' => $ball['x'],
                    'ball_y_ratio' => $ball['y'],
                    'sport_id'     => $alpha->sport_id,
                    'tags'         => 'qa',
                ], $alpha, $paths['hidden'], $paths['original'], $this->u('qa_admin'));
            } catch (\Throwable $e) {
                $this->creator->discardImages($alpha, $paths);
                throw $e;
            }

            $ids[] = (int) $challenge->id;
            $this->tick(90);
        }

        return $ids;
    }

    /** Club pack the way Admin\ChallengePackController::store + challenge sync does it. */
    private function ensurePack(Club $club, string $slug, string $name, string $description, array $challengeIds, string $difficulty, bool $archived = false): ChallengePack
    {
        $pack = ChallengePack::where('club_id', $club->id)->where('slug', $slug)->first();
        if ($pack !== null) {
            return $pack;
        }

        $admin = $this->u('qa_admin');
        $pack = DB::transaction(function () use ($club, $slug, $name, $description, $challengeIds, $difficulty, $admin) {
            $pack = ChallengePack::create([
                'club_id'     => $club->id,
                'sport_id'    => $club->sport_id,
                'name'        => $name,
                'slug'        => $slug,
                'description' => $description,
                'status'      => ChallengePack::STATUS_ACTIVE,
                'visibility'  => ChallengePack::VISIBILITY_PUBLIC,
                'difficulty'  => $difficulty,
                'sort_order'  => 0,
                'is_featured' => false,
            ]);
            app(ClubContentScope::class)->assertPackChallenges($pack, $challengeIds);
            foreach (array_values($challengeIds) as $i => $id) {
                $pack->challenges()->attach($id, ['sort_order' => $i + 1]);
            }

            $this->audit->log($club, 'pack.created', $admin, $pack, ['pack_id' => $pack->id, 'name' => $pack->name, 'status' => $pack->status]);
            $this->memberships->refreshCounters($club);

            return $pack;
        });

        if ($archived) {
            $this->tick(600);
            DB::transaction(function () use ($club, $pack, $admin) {
                $pack->update(['status' => ChallengePack::STATUS_ARCHIVED]);
                $this->audit->log($club, 'pack.archived', $admin, $pack, [
                    'pack_id' => $pack->id, 'name' => $pack->name, 'status' => $pack->status,
                    'from_status' => ChallengePack::STATUS_ACTIVE, 'count' => $pack->challenges()->count(),
                ]);
                $this->memberships->refreshCounters($club);
            });
        }
        $this->tick(120);

        return $pack;
    }

    // ------------------------------------------------------------------
    // Competitions and play
    // ------------------------------------------------------------------

    private function existingCompetition(Club $club, string $name): ?ClubCompetition
    {
        return ClubCompetition::where('club_id', $club->id)->where('name', $name)->first();
    }

    /** Single attempt, blocked, window day −16 … −10; tie for 1st → shared placement. Finalized at −10d + 5 min. */
    private function seedFinalizedCompetition(Club $alpha, ChallengePack $pack): void
    {
        $name = 'QA Autumn Classic';
        if ($this->existingCompetition($alpha, $name) !== null) {
            return;
        }

        $this->at($this->days(-17));
        $competition = $this->competitions->create($alpha, [
            'name'               => $name,
            'description'        => 'Finished competition with final standings and club trophies (shared 1st place).',
            'starts_at'          => $this->days(-16),
            'ends_at'            => $this->days(-10),
            'replay_mode'        => 'single_attempt',
            'normal_play_policy' => 'blocked',
            'pack_ids'           => [$pack->id],
        ], $this->u('qa_admin'));
        $competition = $this->competitions->publish($competition, $this->u('qa_admin'));

        $base = $this->days(-15)->setTime(19, 0);
        // qa_player_07 and qa_player_08: same score at the same instant → shared placement 1.
        foreach ([['qa_player_07', 0.012], ['qa_player_08', 0.012]] as [$username, $offset]) {
            $this->at($base);
            $this->playCompetition($username, $alpha, $competition, $pack, 1, $offset);
        }
        $later = $base->addHours(2);
        foreach ([['qa_player_01', 0.04], ['qa_player_09', 0.08], ['qa_left', 0.1], ['qa_de', 0.12]] as $i => [$username, $offset]) {
            $this->at($later->addMinutes(20 * $i));
            $this->playCompetition($username, $alpha, $competition, $pack, 1, $offset);
        }

        // The scheduler's finalize, five minutes after the window closed.
        $this->at($this->days(-10)->addMinutes(5));
        $this->close->finalize($competition->fresh());
    }

    /** Normal play of the Training Ground (club all-time leaderboard). */
    private function seedNormalPlay(Club $alpha, ChallengePack $pack): void
    {
        $this->at($this->days(-5)->setTime(18, 0));
        $runs = [
            'qa_player_01' => 0.012, 'qa_player_02' => 0.02, 'qa_player_03' => 0.028, 'qa_player_04' => 0.04,
            'qa_player_05' => 0.048, 'qa_player_06' => 0.06, 'qa_player_07' => 0.068, 'qa_player_08' => 0.08,
            'qa_player_09' => 0.1, 'qa_player_10' => 0.12, 'qa_nl' => 0.04, 'qa_es' => 0.06,
            'qa_banned' => 0.032, 'qa_left' => 0.052,
        ];
        foreach ($runs as $username => $offset) {
            $this->playPack($username, $pack, $offset);
            $this->tick(600);
        }
        // qa_player_11 stops half way (an active normal attempt to resume).
        $this->playPack('qa_player_11', $pack, 0.06, stopAfter: 2);
    }

    /**
     * Live competition: multiple attempts (max 3, best), normal play blocked,
     * window day −2 … +12, played yesterday morning. Standings: 1 · 2 · 2 (tie) · 4 …,
     * plus one player at the attempt limit and one attempt still in progress.
     */
    private function seedActiveCompetition(Club $alpha, ChallengePack $pack): void
    {
        $name = 'QA Derby Days';
        if ($this->existingCompetition($alpha, $name) !== null) {
            return;
        }

        $this->at($this->days(-3)->addHour());
        $competition = $this->competitions->create($alpha, [
            'name'               => $name,
            'description'        => 'Live competition: best of 3 attempts, normal play of the Derby Pack is blocked.',
            'starts_at'          => $this->days(-2),
            'ends_at'            => $this->days(12),
            'replay_mode'        => 'multiple_attempts',
            'max_attempts'       => 3,
            'score_rule'         => 'best',
            'normal_play_policy' => 'blocked',
            'pack_ids'           => [$pack->id],
        ], $this->u('qa_admin'));
        $competition = $this->competitions->publish($competition, $this->u('qa_admin'));

        $base = $this->days(-1)->setTime(7, 0); // always after the start (day −2, same time of day as now)

        $this->at($base);
        $this->playCompetition('qa_player_01', $alpha, $competition, $pack, 1, 0.06);
        $this->tick(900);
        $this->playCompetition('qa_player_01', $alpha, $competition, $pack, 2, 0.012);

        // The tie: identical scores at identical instants with one attempt each → shared rank 2.
        $tie = $base->addHours(1);
        foreach (['qa_player_02', 'qa_player_03'] as $username) {
            $this->at($tie);
            $this->playCompetition($username, $alpha, $competition, $pack, 1, 0.04);
        }

        $this->at($base->addHours(2));
        foreach ([[1, 0.12], [2, 0.1], [3, 0.08]] as [$number, $offset]) { // attempt limit reached
            $this->playCompetition('qa_player_05', $alpha, $competition, $pack, $number, $offset);
            $this->tick(600);
        }
        foreach ([['qa_player_06', 0.2], ['qa_nl', 0.048], ['qa_fr', 0.068]] as [$username, $offset]) {
            $this->playCompetition($username, $alpha, $competition, $pack, 1, $offset);
            $this->tick(600);
        }
        // qa_player_04: an attempt in progress (2 of 4 photos).
        $this->playCompetition('qa_player_04', $alpha, $competition, $pack, 1, 0.028, stopAfter: 2);
    }

    private function seedFutureCompetitions(Club $alpha, ChallengePack $training, ChallengePack $cupFinal): void
    {
        $admin = $this->u('qa_admin');

        if ($this->existingCompetition($alpha, 'QA Cup Final') === null) {
            $upcoming = $this->competitions->create($alpha, [
                'name'               => 'QA Cup Final',
                'description'        => 'Upcoming single-attempt competition (starts in 2 days).',
                'starts_at'          => $this->realNow->addDays(2),
                'ends_at'            => $this->realNow->addDays(9),
                'replay_mode'        => 'single_attempt',
                'normal_play_policy' => 'blocked',
                'pack_ids'           => [$cupFinal->id],
            ], $admin);
            $this->competitions->publish($upcoming, $admin);
        }

        if ($this->existingCompetition($alpha, 'QA Friendly (cancelled)') === null) {
            $cancelled = $this->competitions->create($alpha, [
                'name'               => 'QA Friendly (cancelled)',
                'description'        => 'Published, then cancelled before the start.',
                'starts_at'          => $this->realNow->addDays(20),
                'ends_at'            => $this->realNow->addDays(27),
                'replay_mode'        => 'single_attempt',
                'normal_play_policy' => 'allowed',
                'pack_ids'           => [$training->id],
            ], $admin);
            $cancelled = $this->competitions->publish($cancelled, $admin);
            $this->competitions->cancel($cancelled, $admin, 'QA: cancelled to test the cancelled state');
        }

        if ($this->existingCompetition($alpha, 'QA Winter League (draft)') === null) {
            $this->competitions->create($alpha, [
                'name'               => 'QA Winter League (draft)',
                'description'        => 'Draft competition: edit, publish or cancel it.',
                'starts_at'          => $this->realNow->addDays(30),
                'ends_at'            => $this->realNow->addDays(60),
                'replay_mode'        => 'single_attempt',
                'normal_play_policy' => 'blocked',
                'pack_ids'           => [$training->id, $cupFinal->id],
            ], $admin);
        }
    }

    /** Start (or continue) an attempt and guess `$offset` away from the ball on each photo. */
    private function playCompetition(string $username, Club $club, ClubCompetition $competition, ChallengePack $pack, int $number, float $offset, ?int $stopAfter = null): void
    {
        $user = $this->u($username);
        if (ClubCompetitionAttempt::where('club_competition_id', $competition->id)->where('user_id', $user->id)
            ->where('attempt_number', $number)->exists()) {
            return;
        }

        $attempt = $this->competitionPlay->start($user, $club->fresh(), $competition->fresh(), $pack, $number)['attempt'];
        for ($guesses = 0; $stopAfter === null || $guesses < $stopAfter; $guesses++) {
            $challenge = $this->competitionPlay->currentChallenge($attempt);
            if ($challenge === null) {
                break;
            }
            $this->tick(41);
            [$x, $y] = self::guessNear($challenge, $offset);
            $attempt = $this->competitionPlay->guess($user, $attempt, (int) $challenge->id, $x, $y)['attempt'];
        }
    }

    private function playPack(string $username, ChallengePack $pack, float $offset, ?int $stopAfter = null): void
    {
        $user = $this->u($username);
        if (PackAttempt::where('user_id', $user->id)->where('challenge_pack_id', $pack->id)->exists()) {
            return;
        }

        $this->packAccess->assertCanPlay($user, $pack);
        $attempt = $this->packPlay->startOrResume($user, $pack);
        for ($guesses = 0; $stopAfter === null || $guesses < $stopAfter; $guesses++) {
            $attempt->refresh();
            $challenge = $this->packPlay->currentChallenge($attempt);
            if ($challenge === null || $attempt->status !== PackAttempt::STATUS_ACTIVE) {
                break;
            }
            $this->tick(37);
            [$x, $y] = self::guessNear($challenge, $offset);
            $this->packPlay->submitGuess($user, $attempt, (int) $challenge->id, $x, $y);
        }
    }

    /**
     * A guess exactly `$offset` (ratio units) from the ball, towards the
     * centre so it stays inside the image: score = 100 − 250 × offset.
     *
     * @return array{0: float, 1: float}
     */
    private static function guessNear(Challenge $challenge, float $offset): array
    {
        $bx = (float) $challenge->ball_x_ratio;
        $by = (float) $challenge->ball_y_ratio;
        $dx = 0.5 - $bx;
        $dy = 0.5 - $by;
        $len = sqrt($dx * $dx + $dy * $dy);
        [$ux, $uy] = $len < 1e-9 ? [1.0, 0.0] : [$dx / $len, $dy / $len];

        return [$bx + $ux * $offset, $by + $uy * $offset];
    }

    // ------------------------------------------------------------------
    // Photos and reports
    // ------------------------------------------------------------------

    private function seedPhotos(Club $alpha): void
    {
        $owner = $this->u('qa_owner_alpha');
        $admin = $this->u('qa_admin');

        $this->at($this->days(-1)->setTime(13, 0));
        $steps = [
            'pending'        => null,
            'in_review'      => fn (ClubPhotoSubmission $s) => $this->photos->markInReview($s, $admin),
            'in_preparation' => fn (ClubPhotoSubmission $s) => $this->photos->startPreparation($s, $admin),
            'converted'      => function (ClubPhotoSubmission $s) use ($admin) {
                $seed = self::imageSeed('alpha-photo-converted');
                $ball = QaPitchImage::ballPosition($seed);
                $this->photos->convert($s, $admin, [
                    'title'        => 'QA Converted Photo',
                    'difficulty'   => 'medium',
                    'ball_x_ratio' => $ball['x'],
                    'ball_y_ratio' => $ball['y'],
                    'admin_note'   => 'QA: converted from a club photo',
                ], $this->images->upload($seed, false, 'QA STAGING photo converted'));
            },
            'rejected'       => fn (ClubPhotoSubmission $s) => $this->photos->reject($s, $admin, 'QA: the ball is not visible in this photo.'),
        ];

        foreach ($steps as $status => $step) {
            $note = "[QA] {$status} photo";
            if (ClubPhotoSubmission::where('club_id', $alpha->id)->where('club_note', $note)->exists()) {
                continue;
            }

            $seed = self::imageSeed('alpha-photo-' . ($status === 'converted' ? 'converted' : $status));
            $submission = $this->photos->submit(
                $alpha,
                $this->images->upload($seed, true, 'QA STAGING photo ' . $status),
                $owner,
                ClubPhotoSubmission::SOURCE_CLUB_UPLOAD,
                $note,
                true,
                ClubAuditEvent::ACTOR_CLUB_ADMIN,
            );
            if ($step !== null) {
                $this->tick(3600);
                $step($submission);
            }
            $this->tick(600);
        }
    }

    private function seedReports(Club $alpha): void
    {
        $derby = $this->existingCompetition($alpha, 'QA Derby Days');

        $reporter = $this->u('qa_player_09');
        if ($derby !== null && !ClubContentReport::where('club_id', $alpha->id)->where('reporter_user_id', $reporter->id)->exists()) {
            $this->reports->create($alpha, $reporter, ClubContentReport::SUBJECT_COMPETITION, $derby->public_id, 'offensive',
                'QA: open report for the moderation queue.');
        }

        $reporter = $this->u('qa_player_10');
        if (!ClubContentReport::where('club_id', $alpha->id)->where('reporter_user_id', $reporter->id)->exists()) {
            $report = $this->reports->create($alpha, $reporter, ClubContentReport::SUBJECT_MEMBER, (string) $this->u('qa_player_06')->id, 'spam',
                'QA: report that was already resolved.')['report'];
            $this->tick(1800);
            $this->reports->resolve($report, $this->u('qa_admin'), ClubContentReport::STATUS_DISMISSED, 'QA: resolved by staff.');
        }
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    private function printSummary(): void
    {
        $alpha = Club::where('slug', 'qa-fc-alpha')->first();
        $qaClubIds = Club::where('slug', 'like', 'qa-%')->pluck('id');
        $alphaCodes = $alpha ? ClubInviteCode::where('club_id', $alpha->id)->get() : collect();
        $competitions = $alpha ? ClubCompetition::where('club_id', $alpha->id)->get() : collect();

        $rows = [
            'QA accounts'                => User::where('email', 'like', '%@' . self::DOMAIN)->count(),
            'QA clubs'                   => $qaClubIds->count() . ' (' . Club::whereIn('id', $qaClubIds)->get()->countBy(fn (Club $c) => $c->status->value)
                ->map(fn ($n, $s) => "{$s} {$n}")->implode(', ') . ')',
            'QA FC Alpha active members' => $alpha?->members_count ?? 0,
            'Alpha invite codes'         => $alphaCodes->count() . ' (usable ' . $alphaCodes->filter(fn ($c) => !$c->isRevoked() && !$c->isExpired() && !$c->isExhausted())->count() . ')',
            'Alpha club challenges'      => $alpha ? Challenge::where('club_id', $alpha->id)->count() : 0,
            'Alpha club packs'           => $alpha ? ChallengePack::where('club_id', $alpha->id)->count() : 0,
            'Alpha competitions'         => $competitions->map(fn (ClubCompetition $c) => $c->phase()->value)->countBy()->map(fn ($n, $p) => "{$p} {$n}")->implode(', '),
            'Competition attempts'       => ClubCompetitionAttempt::whereIn('club_competition_id', $competitions->pluck('id'))->count(),
            'Competition finishes'       => ClubCompetitionFinish::whereIn('club_competition_id', $competitions->pluck('id'))->count(),
            'Normal pack attempts'       => PackAttempt::whereHas('pack', fn ($q) => $q->whereIn('club_id', $qaClubIds))->count(),
            'Photo submissions'          => ClubPhotoSubmission::whereIn('club_id', $qaClubIds)->get()->countBy(fn ($s) => $s->status->value)->map(fn ($n, $s) => "{$s} {$n}")->implode(', '),
            'Content reports'            => ClubContentReport::whereIn('club_id', $qaClubIds)->get()->countBy('status')->map(fn ($n, $s) => "{$s} {$n}")->implode(', '),
            'Club audit events'          => ClubAuditEvent::whereIn('club_id', $qaClubIds)->count(),
        ];

        $this->line('ClubsStagingSeeder done (no mail, push or HTTP was sent).');
        foreach ($rows as $label => $value) {
            $this->line(sprintf('  %-28s %s', $label, $value));
        }
        $this->line('  QA usernames (password = BALLPICKER_STAGING_SEED_PASSWORD, email <username>@' . self::DOMAIN . '):');
        $this->line('    ' . implode(', ', array_keys(self::accounts())));
        $this->line('  QA clubs: ' . Club::whereIn('id', $qaClubIds)->orderBy('name')->pluck('name')->implode(', '));
        $this->line('  Invite codes are not printed: read them in /admin (club → Codes) or the club portal.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function u(string $username): User
    {
        return $this->users[$username] ?? throw new \LogicException("Unknown QA account [{$username}].");
    }

    /** @return list<string> */
    private function players(): array
    {
        return array_map(fn (int $i) => sprintf('qa_player_%02d', $i), range(1, 12));
    }

    private function sportId(string $slug): ?int
    {
        return Sport::where('slug', $slug)->value('id') ?? Sport::where('slug', 'football')->value('id');
    }

    /** `$offset` days from the real now (same time of day). */
    private function days(int $offset): CarbonImmutable
    {
        return $this->realNow->addDays($offset);
    }

    private function at(CarbonInterface $moment): void
    {
        Carbon::setTestNow(Carbon::instance($moment));
    }

    private function tick(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    private static function imageSeed(string $key): int
    {
        return (int) sprintf('%u', crc32('ballpicker-qa:' . $key)) & 0x7fffffff;
    }

    private function line(string $message): void
    {
        $this->command?->getOutput()->writeln($message);
    }

    private function error(string $message): void
    {
        $this->command?->getOutput()->writeln('<error>' . $message . '</error>');
    }
}
