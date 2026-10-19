<?php

namespace Tests\Feature\Clubs\Foundation;

use App\Models\ClubAuditEvent;
use App\Models\User;
use App\Services\Clubs\ClubAuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

class ClubAuditLoggerTest extends TestCase
{
    use CreatesClubs;
    use RefreshDatabase;

    public function test_every_allow_listed_action_has_a_label_in_every_language(): void
    {
        foreach (['en', 'nl', 'fr', 'de', 'es'] as $lang) {
            foreach (ClubAuditEvent::ACTIONS as $action) {
                $key = 'clubs.audit.' . $action;
                $this->assertTrue(\Illuminate\Support\Facades\Lang::has($key, $lang, false), "lang/{$lang}/clubs.php misses {$key}");
            }
        }
    }

    public function test_unknown_actions_and_actor_kinds_are_rejected(): void
    {
        $club = $this->makeClub();
        $logger = app(ClubAuditLogger::class);

        try {
            $logger->log($club, 'club.hacked', null);
            $this->fail('unknown action accepted');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $logger->log($club, 'club.updated', null, null, [], 'robot');
    }

    public function test_metadata_is_allow_listed_and_scrubbed(): void
    {
        $club  = $this->makeClub();
        $actor = User::factory()->create();

        $event = app(ClubAuditLogger::class)->log($club, 'club.settings_changed', $actor, $club, [
            'before'  => ['join_enabled' => true, 'email' => 'leak@example.test'],
            'after'   => ['join_enabled' => false, 'code' => 'ABCDEFGH'],
            'email'   => 'leak@example.test',
            'payload' => ['anything' => 'goes'],
        ]);

        $this->assertSame(['before' => ['join_enabled' => true], 'after' => ['join_enabled' => false]], $event->metadata);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame('platform_admin', $event->actor_kind);
        $this->assertNotNull($event->ip_hash);
        $this->assertSame(64, strlen($event->ip_hash));
        $this->assertSame('Club settings changed', $event->label());
    }

    public function test_audit_rows_roll_back_with_the_change(): void
    {
        $club = $this->makeClub();

        try {
            DB::transaction(function () use ($club) {
                app(ClubAuditLogger::class)->log($club, 'club.updated', null);
                throw new \RuntimeException('change failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, ClubAuditEvent::count());
    }

    public function test_events_are_mirrored_to_the_app_log_without_secrets(): void
    {
        $club = $this->makeClub();
        $events = [];
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->andReturnUsing(function ($level, $message, $context) use (&$events) {
            $events[] = [$message, $context];
        });

        app(ClubAuditLogger::class)->log($club, 'code.created', User::factory()->create(), null, ['code_masked' => 'ABCD-••••']);

        $this->assertSame('club.code.created', $events[0][0]);
        $this->assertSame($club->id, $events[0][1]['club_id']);
        $this->assertStringNotContainsString('ABCD', json_encode($events[0][1]));
    }
}
