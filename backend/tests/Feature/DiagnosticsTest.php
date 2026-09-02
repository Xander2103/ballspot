<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\League;
use App\Models\Sport;
use App\Models\User;
use App\Services\DiagnosticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /admin/diagnostics — read-only beta observability page.
 */
class DiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function sport(string $slug = 'football', string $status = Sport::STATUS_ACTIVE): Sport
    {
        return Sport::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'status' => $status]);
    }

    private function challenge(Sport $sport, string $title, array $overrides = []): Challenge
    {
        return Challenge::create(array_merge([
            'sport_id'          => $sport->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'usage_pool'        => Challenge::POOL_GENERAL,
            'hidden_image_path' => "challenges/hidden/{$title}.jpg",
        ], $overrides));
    }

    private function league(User $owner, Sport $sport, array $overrides = []): League
    {
        return League::create(array_merge([
            'name'           => 'Cup',
            'join_code'      => strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'owner_user_id'  => $owner->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 7,
            'rounds_per_day' => 1,
            'status'         => 'lobby',
        ], $overrides));
    }

    private function snapshot(): array
    {
        return app(DiagnosticsService::class)->snapshot();
    }

    private function warningMessages(array $snapshot): array
    {
        return array_column($snapshot['warnings'], 'message');
    }

    // ------------------------------------------------------------------
    // Access control
    // ------------------------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin/diagnostics')->assertRedirect('/admin/login');
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/admin/diagnostics')->assertStatus(403);
    }

    public function test_admin_can_view_diagnostics(): void
    {
        $this->sport();
        $this->actingAs($this->admin())->get('/admin/diagnostics')
            ->assertOk()
            ->assertSee('Diagnostics');
    }

    // ------------------------------------------------------------------
    // Page content
    // ------------------------------------------------------------------

    public function test_page_shows_all_sections(): void
    {
        $this->sport();
        $html = $this->actingAs($this->admin())->get('/admin/diagnostics')->assertOk()->getContent();

        foreach (['warnings', 'app', 'log', 'queue', 'daily', 'content', 'tournaments', 'packs', 'storage', 'activity', 'commands'] as $section) {
            $this->assertStringContainsString("data-section=\"{$section}\"", $html, "Missing section: {$section}");
        }

        // Manual operations are listed as text.
        foreach ([
            'tail -n 100 storage/logs/laravel.log',
            'php artisan queue:failed',
            'sudo supervisorctl status',
            'php artisan schedule:list',
            'php artisan ballspot:backup-content',
        ] as $cmd) {
            $this->assertStringContainsString($cmd, $html);
        }
    }

    public function test_nav_has_diagnostics_link(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings')
            ->assertOk()
            ->assertSee('href="/admin/diagnostics"', false);
    }

    public function test_page_never_exposes_secrets(): void
    {
        // The real key (a bogus one breaks the encrypter). Strip the base64:
        // prefix so the assertion also catches a "decoded" rendering.
        $appKey  = ltrim((string) config('app.key'), 'base64:');
        $dbPass  = 'db-secret-password-zz91';
        $mailPw  = 'mail-secret-password-qq42';
        $betaCode = 'SUPERSECRETBETA2026';

        $this->assertNotSame('', $appKey, 'Test needs an APP_KEY to assert against.');

        config([
            'database.connections.mysql.password'  => $dbPass,
            'database.connections.sqlite.password' => $dbPass,
            'mail.mailers.smtp.password'           => $mailPw,
            'ballspot.beta_code'                   => $betaCode,
        ]);

        $html = $this->actingAs($this->admin())->get('/admin/diagnostics')->assertOk()->getContent();

        foreach ([$appKey, $dbPass, $mailPw, $betaCode, 'APP_KEY=', 'DB_PASSWORD=', 'MAIL_PASSWORD=', 'BALLPICKER_BETA_CODE='] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "Diagnostics leaked: {$secret}");
        }

        // The beta gate is reported as on/off only.
        $this->assertStringContainsString('Beta gate', $html);
    }

    public function test_page_does_not_mutate_data(): void
    {
        $sport = $this->sport();
        $admin = $this->admin();
        $this->challenge($sport, 'Keep');
        $before = [
            'challenges' => Challenge::count(),
            'dailies'    => DailyChallenge::count(),
            'leagues'    => League::count(),
            'users'      => User::count(),
        ];

        $this->actingAs($admin)->get('/admin/diagnostics')->assertOk();

        $this->assertSame($before, [
            'challenges' => Challenge::count(),
            'dailies'    => DailyChallenge::count(),
            'leagues'    => League::count(),
            'users'      => User::count(),
        ]);
    }

    // ------------------------------------------------------------------
    // Warnings
    // ------------------------------------------------------------------

    public function test_warns_when_daily_pool_is_low_and_clears_when_stocked(): void
    {
        $sport = $this->sport();
        for ($i = 0; $i < DiagnosticsService::DAILY_POOL_LOW - 1; $i++) {
            $this->challenge($sport, "d{$i}");
        }

        $messages = implode("\n", $this->warningMessages($this->snapshot()));
        $this->assertStringContainsString('Daily pool is low', $messages);

        $this->challenge($sport, 'one-more');

        $messages = implode("\n", $this->warningMessages($this->snapshot()));
        $this->assertStringNotContainsString('Daily pool is low', $messages);
    }

    public function test_daily_pool_excludes_used_and_non_daily_pool_challenges(): void
    {
        $sport = $this->sport();
        $used = $this->challenge($sport, 'used');
        DailyChallenge::create(['challenge_id' => $used->id, 'challenge_date' => '2020-01-01', 'status' => 'archived']);
        $this->challenge($sport, 'tournament-only', ['usage_pool' => Challenge::POOL_TOURNAMENT]);
        $this->challenge($sport, 'draft', ['status' => 'draft']);
        $this->challenge($sport, 'no-image', ['hidden_image_path' => null]);
        $this->challenge($sport, 'fresh');

        $snapshot = $this->snapshot();

        $this->assertSame(1, $snapshot['daily']['pool_available']);
        $this->assertSame(1, $snapshot['content']['used_as_daily']);
    }

    public function test_warns_when_tournament_pool_is_low_for_an_active_sport_only(): void
    {
        $football = $this->sport('football');
        $hockey   = $this->sport('hockey', Sport::STATUS_COMING_SOON);

        for ($i = 0; $i < DiagnosticsService::TOURNAMENT_POOL_LOW - 1; $i++) {
            $this->challenge($football, "f{$i}", ['usage_pool' => Challenge::POOL_TOURNAMENT]);
        }
        // Hockey has nothing but is not active: no warning expected.

        $messages = $this->warningMessages($this->snapshot());
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_starts_with($m, 'Football: only') && str_contains($m, 'tournament-eligible')));
        $this->assertFalse(collect($messages)->contains(fn ($m) => str_starts_with($m, 'Hockey:')));

        $this->challenge($football, 'f-last', ['usage_pool' => Challenge::POOL_TOURNAMENT]);

        $messages = $this->warningMessages($this->snapshot());
        $this->assertFalse(collect($messages)->contains(fn ($m) => str_starts_with($m, 'Football: only') && str_contains($m, 'tournament-eligible')));
    }

    public function test_per_sport_counts_are_reported(): void
    {
        $sport = $this->sport();
        $this->challenge($sport, 'general');
        $this->challenge($sport, 'pack', ['usage_pool' => Challenge::POOL_PACK]);
        $this->challenge($sport, 'daily', ['usage_pool' => Challenge::POOL_DAILY]);

        $row = collect($this->snapshot()['content']['per_sport'])->firstWhere('slug', 'football');

        $this->assertSame(3, $row['active_ready']);
        $this->assertSame(2, $row['daily_eligible']);      // general + daily
        $this->assertSame(1, $row['tournament_eligible']); // general only
        $this->assertSame(1, $row['pack_only']);
    }

    public function test_warns_when_no_daily_today_and_when_today_is_only_scheduled(): void
    {
        $sport = $this->sport();
        $c = $this->challenge($sport, 'today');

        $messages = implode("\n", $this->warningMessages($this->snapshot()));
        $this->assertStringContainsString('No daily challenge exists for today', $messages);

        $daily = DailyChallenge::create(['challenge_id' => $c->id, 'challenge_date' => now()->toDateString(), 'status' => 'scheduled']);

        $snapshot = $this->snapshot();
        $this->assertSame('scheduled', $snapshot['daily']['today_status']);
        $messages = implode("\n", $this->warningMessages($snapshot));
        $this->assertStringContainsString('still "scheduled", not "active"', $messages);

        $daily->update(['status' => 'active']);

        $snapshot = $this->snapshot();
        $this->assertSame('active', $snapshot['daily']['today_status']);
        $messages = implode("\n", $this->warningMessages($snapshot));
        $this->assertStringNotContainsString('No daily challenge exists', $messages);
        $this->assertStringNotContainsString('still "scheduled"', $messages);
    }

    public function test_zero_challenge_packs_are_counted_and_public_ones_warn(): void
    {
        $sport = $this->sport();
        $c = $this->challenge($sport, 'in-pack', ['usage_pool' => Challenge::POOL_PACK]);

        $full = ChallengePack::create(['name' => 'Full', 'slug' => 'full', 'status' => 'active', 'visibility' => 'public', 'sport_id' => $sport->id]);
        $full->challenges()->attach($c->id, ['sort_order' => 0]);
        ChallengePack::create(['name' => 'Empty public', 'slug' => 'empty-public', 'status' => 'active', 'visibility' => 'public']);
        ChallengePack::create(['name' => 'Empty draft', 'slug' => 'empty-draft', 'status' => 'draft', 'visibility' => 'hidden']);
        ChallengePack::create(['name' => 'Empty archived', 'slug' => 'empty-archived', 'status' => 'archived', 'visibility' => 'hidden']);

        $snapshot = $this->snapshot();

        $this->assertSame(2, $snapshot['packs']['active_public']);
        $this->assertSame(2, $snapshot['packs']['zero_challenges']); // archived excluded
        $this->assertContains('Empty public', $snapshot['packs']['zero_challenge_names']);

        $messages = implode("\n", $this->warningMessages($snapshot));
        $this->assertStringContainsString('Empty public', $messages);
        $this->assertStringNotContainsString('Empty draft', $messages);
    }

    public function test_pack_trophy_counts(): void
    {
        $badge = \App\Models\Badge::create(['code' => 'pack_x', 'name' => 'X', 'description' => 'x', 'icon' => '🏆', 'category' => 'pack', 'rarity' => 'common']);
        ChallengePack::create(['name' => 'Trophy', 'slug' => 'trophy', 'status' => 'active', 'visibility' => 'public', 'completion_badge_id' => $badge->id]);
        ChallengePack::create(['name' => 'Plain', 'slug' => 'plain', 'status' => 'active', 'visibility' => 'public']);

        $snapshot = $this->snapshot();

        $this->assertSame(1, $snapshot['packs']['with_trophy']);
        $this->assertSame(1, $snapshot['packs']['without_trophy']);
    }

    public function test_expired_active_tournaments_are_counted_and_warned(): void
    {
        $owner = User::factory()->create();
        $sport = $this->sport();

        $this->league($owner, $sport, ['status' => 'active', 'starts_at' => now()->subDays(10), 'ends_at' => now()->subDay()]);
        $this->league($owner, $sport, ['status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(6)]);
        $this->league($owner, $sport, ['status' => 'completed', 'starts_at' => now()->subDays(10), 'ends_at' => now()->subDay()]);
        $this->league($owner, $sport, ['status' => 'lobby']);

        $snapshot = $this->snapshot();

        $this->assertSame(1, $snapshot['tournaments']['expired_active']);
        $this->assertSame(2, $snapshot['tournaments']['active']);
        $this->assertSame(1, $snapshot['tournaments']['lobby']);
        $this->assertSame(1, $snapshot['tournaments']['completed']);

        $messages = implode("\n", $this->warningMessages($snapshot));
        $this->assertStringContainsString('past their end date', $messages);
    }

    public function test_failed_jobs_are_counted_without_exposing_payloads(): void
    {
        $payload = 'SECRET-JOB-PAYLOAD-BODY-123';
        DB::table('failed_jobs')->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => json_encode(['data' => $payload]),
            'exception'  => "RuntimeException: boom {$payload}\n#0 stack",
            'failed_at'  => now(),
        ]);

        $snapshot = $this->snapshot();
        $this->assertSame(1, $snapshot['queue']['failed_jobs']);
        $this->assertNotNull($snapshot['queue']['latest_failed_at']);
        $messages = implode("\n", $this->warningMessages($snapshot));
        $this->assertStringContainsString('failed job', $messages);

        $html = $this->actingAs($this->admin())->get('/admin/diagnostics')->assertOk()->getContent();
        $this->assertStringNotContainsString($payload, $html);
    }

    public function test_storage_section_reports_root_and_link(): void
    {
        $snapshot = $this->snapshot();

        $this->assertArrayHasKey('root_exists', $snapshot['storage']);
        $this->assertArrayHasKey('root_writable', $snapshot['storage']);
        $this->assertArrayHasKey('link_exists', $snapshot['storage']);
        $this->assertCount(4, $snapshot['storage']['directories']);
        // Relative labels only — never the absolute server path.
        $this->assertSame('storage/app/public', $snapshot['storage']['root']);
    }

    public function test_app_section_has_no_key_material(): void
    {
        $snapshot = $this->snapshot();

        $this->assertArrayNotHasKey('key', $snapshot['app']);
        $this->assertSame((string) config('app.env'), $snapshot['app']['env']);
        $this->assertIsBool($snapshot['app']['debug']);
        $this->assertSame(config('ballspot.version'), $snapshot['app']['version']);
    }
}
