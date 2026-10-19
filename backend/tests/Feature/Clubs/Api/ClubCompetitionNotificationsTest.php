<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Enums\CompetitionState;
use App\Models\ClubCompetition;
use App\Models\PushToken;
use App\Models\User;
use App\Services\Clubs\ClubCompetitionCloseService;
use App\Services\Clubs\ClubCompetitionNotifier;
use App\Services\Clubs\ClubCompetitionService;
use App\Services\ExpoPushService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Clubs\Competitions\CompetitionTestCase;

/**
 * Wave F member notifications (contracts §9): category `club_updates`
 * (notification settings), pushes for competition published / started /
 * finalized — at most once per event even when triggered twice, only to
 * active members with the category on and a push token, in each
 * recipient's language, with the placement on finalize. Failures are
 * logged and swallowed. Expo is always faked (no real pushes).
 */
class ClubCompetitionNotificationsTest extends CompetitionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ballspot.notifications.push_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['*' => fn (Request $r) => Http::response(['data' => array_fill(0, count($r->data()), ['status' => 'ok'])], 200)]);
    }

    private function withPushToken(User $user): User
    {
        PushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[u' . $user->id . ']']);

        return $user;
    }

    /** @return list<array> every Expo message sent so far */
    private function sent(): array
    {
        return collect(Http::recorded())->flatMap(fn ($pair) => $pair[0]->data())->values()->all();
    }

    /** @return array<string, array> messages keyed by token */
    private function byToken(): array
    {
        return collect($this->sent())->keyBy('to')->all();
    }

    private function token(User $user): string
    {
        return 'ExponentPushToken[u' . $user->id . ']';
    }

    private function draft(array $attrs = []): ClubCompetition
    {
        return $this->makeCompetition($this->club, [$this->makeClubPack($this->club, 2)], array_merge([
            'name'      => 'Autumn Cup',
            'starts_at' => now()->addDay(),
            'ends_at'   => now()->addDays(8),
        ], $attrs));
    }

    private function publish(ClubCompetition $c): ClubCompetition
    {
        return app(ClubCompetitionService::class)->publish($c, $this->owner);
    }

    // ------------------------------------------------------------------ settings

    public function test_club_updates_is_a_notification_setting_default_on(): void
    {
        $user = User::factory()->create();

        $this->api($user)->getJson('/api/me/notification-settings')->assertOk()->assertJsonPath('club_updates_enabled', true);
        $this->api($user)->putJson('/api/me/notification-settings', ['club_updates_enabled' => false])->assertOk()
            ->assertJsonPath('club_updates_enabled', false)
            ->assertJsonPath('admin_notifications_enabled', true);
        $this->api($user)->getJson('/api/me/notification-settings')->assertOk()->assertJsonPath('club_updates_enabled', false);
        $this->api($user)->putJson('/api/me/notification-settings', ['club_updates_enabled' => 'maybe'])->assertUnprocessable()
            ->assertJsonValidationErrors('club_updates_enabled');

        $this->api($user)->getJson('/api/me/export')->assertOk()->assertJsonPath('notification_settings.club_updates_enabled', false);
    }

    // ------------------------------------------------------------------ published

    public function test_publish_pushes_active_opted_in_members_with_tokens_once(): void
    {
        $anna    = $this->withPushToken($this->member('anna'));
        $optOut  = $this->withPushToken($this->member('optout'));
        $optOut->notificationSettings()->update(['club_updates_enabled' => false]);
        $noToken = $this->member('notoken');
        $left    = $this->withPushToken(User::factory()->create());
        $this->addMember($this->club, $left, ClubRole::Member, ClubMemberStatus::Left);
        $banned  = $this->withPushToken(User::factory()->create());
        $this->addMember($this->club, $banned, ClubRole::Member, ClubMemberStatus::Banned);
        $outsider = $this->withPushToken(User::factory()->create());
        $anonymized = $this->withPushToken($this->member('gone'));
        $anonymized->forceFill(['anonymized_at' => now()])->save();
        // Admin announcements off does not affect club updates.
        $adminOff = $this->withPushToken($this->member('adminoff'));
        $adminOff->notificationSettings()->update(['admin_notifications_enabled' => false]);
        $this->withPushToken($this->owner);

        $c = $this->publish($this->draft());

        $msgs = $this->byToken();
        $this->assertEqualsCanonicalizing([$this->token($anna), $this->token($adminOff), $this->token($this->owner)], array_keys($msgs));
        $msg = $msgs[$this->token($anna)];
        $this->assertSame(['type' => 'club_competition', 'club_id' => $this->club->public_id, 'competition_id' => $c->public_id], $msg['data']);
        $this->assertSame($this->club->name . ': new competition', $msg['title']);
        $this->assertStringContainsString('“Autumn Cup” starts', $msg['body']);
        $this->assertStringNotContainsString(':date', $msg['body']);
        $this->assertNotNull($c->fresh()->notified_published_at);

        // Triggered again (retry / second call): nothing more is sent.
        app(ClubCompetitionNotifier::class)->published($c);
        app(ClubCompetitionNotifier::class)->published($c);
        $this->assertCount(3, $this->sent());

        // The tick before the start sends nothing either.
        $this->artisan('ballspot:clubs:competition-tick')->assertSuccessful();
        $this->assertCount(3, $this->sent());

        $this->assertNotContains($this->token($optOut), array_keys($msgs));
        $this->assertNotContains($this->token($outsider), array_keys($msgs));
        $this->assertNull($noToken->pushTokens()->first());
    }

    public function test_publish_cancel_loops_are_capped_per_club_per_day(): void
    {
        config(['ballspot.clubs.notifications.max_publish_pushes_per_day' => 2]);
        $anna    = $this->withPushToken($this->member('anna'));
        $service = app(ClubCompetitionService::class);

        foreach (range(1, 4) as $i) {
            $c = $this->publish($this->draft(['name' => 'Loop ' . $i]));
            $this->assertNotNull($c->fresh()->notified_published_at, 'the event is always closed');
            $service->cancel($c->fresh(), $this->owner);
        }

        // Only the first two publishes reached members; the rest were closed silently.
        $this->assertCount(2, collect($this->sent())->where('to', $this->token($anna)));

        // A day later the allowance is back.
        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->publish($this->draft(['name' => 'Next day', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(8)]));
        $this->assertCount(3, collect($this->sent())->where('to', $this->token($anna)));
        Carbon::setTestNow();
    }

    public function test_copy_follows_each_recipients_language(): void
    {
        $nl = $this->withPushToken($this->member('nl_user'));
        $nl->forceFill(['preferred_language' => 'nl'])->save();
        $de = $this->withPushToken($this->member('de_user'));
        $de->forceFill(['preferred_language' => 'de'])->save();
        $en = $this->withPushToken($this->member('en_user'));

        $this->publish($this->draft());

        $msgs = $this->byToken();
        $this->assertSame($this->club->name . ': nieuwe competitie', $msgs[$this->token($nl)]['title']);
        $this->assertStringContainsString('Maak je klaar', $msgs[$this->token($nl)]['body']);
        $this->assertSame($this->club->name . ': neuer Wettbewerb', $msgs[$this->token($de)]['title']);
        $this->assertSame($this->club->name . ': new competition', $msgs[$this->token($en)]['title']);
        $this->assertSame('en', app()->getLocale(), 'the request locale is untouched');
    }

    // ------------------------------------------------------------------ started

    public function test_tick_sends_started_once_after_the_start(): void
    {
        $anna = $this->withPushToken($this->member('anna'));
        $c    = $this->publish($this->draft(['starts_at' => now()->addMinutes(5)]));
        $this->assertCount(1, $this->sent());

        Carbon::setTestNow(now()->addMinutes(5));
        $this->artisan('ballspot:clubs:competition-tick')->expectsOutputToContain('start pushes: 1')->assertSuccessful();
        $this->artisan('ballspot:clubs:competition-tick')->expectsOutputToContain('start pushes: 0')->assertSuccessful();
        app(ClubCompetitionNotifier::class)->started($c);

        $sent = $this->sent();
        $this->assertCount(2, $sent);
        $this->assertSame($this->club->name . ': competition started', $sent[1]['title']);
        $this->assertSame('“Autumn Cup” has started. Play now!', $sent[1]['body']);
        $this->assertSame($this->token($anna), $sent[1]['to']);
        $this->assertNotNull($c->fresh()->notified_started_at);
        $this->assertNotNull($c->fresh()->rules_locked_at);
    }

    public function test_started_is_sent_even_when_a_player_locked_the_rules_first(): void
    {
        $this->withPushToken($this->member('anna'));
        $player = $this->member('player');
        $pack   = $this->makeClubPack($this->club, 2);
        $c      = $this->makeCompetition($this->club, [$pack], ['state' => CompetitionState::Published, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(), 'notified_published_at' => now()]);
        $this->startAttempt($player, $c, $pack)->assertCreated();
        $this->assertNotNull($c->fresh()->rules_locked_at);

        $this->artisan('ballspot:clubs:competition-tick')->expectsOutputToContain('start pushes: 1')->assertSuccessful();
        $this->assertCount(1, $this->sent());
    }

    public function test_publishing_after_the_start_sends_started_only(): void
    {
        $this->withPushToken($this->member('anna'));
        $c = $this->publish($this->draft(['starts_at' => now()->subMinutes(10)]));

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame($this->club->name . ': competition started', $sent[0]['title']);
        $c->refresh();
        $this->assertNotNull($c->notified_started_at);
        $this->assertNotNull($c->notified_published_at);

        $this->artisan('ballspot:clubs:competition-tick')->assertSuccessful();
        app(ClubCompetitionNotifier::class)->published($c);
        $this->assertCount(1, $this->sent());
    }

    public function test_no_start_push_for_ended_drafts_or_cancelled_competitions(): void
    {
        $this->withPushToken($this->member('anna'));
        $this->draft(['starts_at' => now()->subHour()]); // draft
        $this->makeCompetition($this->club, [$this->makeClubPack($this->club, 1)], ['state' => CompetitionState::Cancelled, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

        $this->artisan('ballspot:clubs:competition-tick')->expectsOutputToContain('start pushes: 0')->assertSuccessful();
        $this->assertSame([], $this->sent());
    }

    // ------------------------------------------------------------------ finalized

    public function test_finalize_pushes_the_placement_once(): void
    {
        $pack  = $this->makeClubPack($this->club, 2);
        $c     = $this->liveCompetition([$pack], ['ends_at' => now()->addHour(), 'notified_published_at' => now(), 'notified_started_at' => now()]);
        $anna  = $this->withPushToken($this->member('anna'));
        $bob   = $this->withPushToken($this->member('bob'));
        $idle  = $this->withPushToken($this->member('idle'));
        $nlBob = $bob->forceFill(['preferred_language' => 'nl']);
        $nlBob->save();
        $this->play($anna, $c, $pack, [100, 100]);
        $this->play($bob, $c, $pack, [80, 80]);

        Carbon::setTestNow($c->ends_at->copy()->addMinute());
        $this->artisan('ballspot:clubs:competition-tick')->expectsOutputToContain('finalized: 1')->assertSuccessful();

        $msgs = $this->byToken();
        $this->assertCount(3, $msgs);
        $this->assertSame($this->club->name . ': final standings', $msgs[$this->token($anna)]['title']);
        $this->assertSame('“' . $c->name . '” is over. You finished #1 of 2.', $msgs[$this->token($anna)]['body']);
        $this->assertSame('“' . $c->name . '” is afgelopen. Je eindigde als #2 van 2.', $msgs[$this->token($bob)]['body']);
        $this->assertSame('“' . $c->name . '” is over. See the final standings.', $msgs[$this->token($idle)]['body']);
        $this->assertSame(['type' => 'club_competition', 'club_id' => $this->club->public_id, 'competition_id' => $c->public_id], $msgs[$this->token($idle)]['data']);

        // Finalize again / re-finalize after a void / another tick: no second push.
        app(ClubCompetitionCloseService::class)->finalize($c);
        app(ClubCompetitionCloseService::class)->finalize($c, $this->owner, true, 'platform_admin');
        $this->artisan('ballspot:clubs:competition-tick')->assertSuccessful();
        app(ClubCompetitionNotifier::class)->finalized($c);
        $this->assertCount(3, $this->sent());
        $this->assertNotNull($c->fresh()->notified_finalized_at);
    }

    // ------------------------------------------------------------------ failures

    public function test_expo_failure_is_swallowed_and_never_resent(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('Bad gateway', 502)]);
        $this->withPushToken($this->member('anna'));

        $c = $this->publish($this->draft());

        $this->assertSame('published', $c->state->value);
        $this->assertNotNull($c->fresh()->notified_published_at);
        Http::assertSentCount(1);

        app(ClubCompetitionNotifier::class)->published($c);
        Http::assertSentCount(1);
    }

    public function test_an_exception_in_the_push_layer_never_fails_publish_or_tick(): void
    {
        $this->withPushToken($this->member('anna'));
        $this->app->instance(ExpoPushService::class, new class extends ExpoPushService {
            public function sendMessages(array $messages): array
            {
                throw new \RuntimeException('expo exploded');
            }
        });
        $this->app->forgetInstance(ClubCompetitionNotifier::class);
        $this->app->forgetInstance(ClubCompetitionService::class);

        $c = app(ClubCompetitionService::class)->publish($this->draft(['starts_at' => now()->addMinutes(2)]), $this->owner);
        $this->assertSame('published', $c->state->value);
        $this->assertNotNull($c->fresh()->notified_published_at);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('ballspot:clubs:competition-tick')->assertSuccessful();
        $this->assertNotNull($c->fresh()->notified_started_at);
        $this->assertNotNull($c->fresh()->rules_locked_at);
    }

    public function test_push_disabled_or_suspended_club_sends_nothing(): void
    {
        $this->withPushToken($this->member('anna'));
        config(['ballspot.notifications.push_enabled' => false]);
        $c = $this->publish($this->draft());
        $this->assertSame([], $this->sent());
        $this->assertNotNull($c->fresh()->notified_published_at);

        config(['ballspot.notifications.push_enabled' => true]);
        $pack = $this->makeClubPack($this->club, 1);
        $live = $this->makeCompetition($this->club, [$pack], ['state' => CompetitionState::Published, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(), 'notified_published_at' => now()]);
        $this->club->forceFill(['status' => ClubStatus::Suspended])->save();
        $this->artisan('ballspot:clubs:competition-tick')->assertSuccessful();
        $this->assertSame([], $this->sent());
        $this->assertNotNull($live->fresh()->notified_started_at, 'claimed: never sent late after a reactivation');
    }
}
