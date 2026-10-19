<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Enums\CompetitionState;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionFinish;
use App\Models\ClubMember;
use App\Models\User;
use App\Services\ExpoPushService;
use App\Support\AppLog;
use Illuminate\Support\Facades\DB;

/**
 * Member pushes for club competitions (Wave F, contracts §9), category
 * `club_updates` (notification_settings.club_updates_enabled, default on):
 *
 *  - published  — ClubCompetitionService::publish(), after the commit. A
 *                 competition published after its start sends "started"
 *                 instead (and never "published" later);
 *  - started    — ballspot:clubs:competition-tick once starts_at passed
 *                 (whoever locked the rules: the tick, a player, the publish);
 *  - finalized  — ClubCompetitionCloseService::finalize(), with the
 *                 recipient's placement when they have one.
 *
 * At most once per event: the competition's notified_*_at column is CLAIMED
 * with a conditional UPDATE (… WHERE notified_x_at IS NULL) BEFORE anything
 * is sent, so a second trigger, a retry or a concurrent tick can only ever
 * skip, never duplicate. Recipients: ACTIVE members of an ACTIVE club, not
 * anonymized, with ≥ 1 push token, without club_updates opted out. Copy in
 * each recipient's language (lang/*\/clubs.php `push.*`), payload
 * `data: {type: "club_competition", club_id, competition_id}` (public ids).
 *
 * Never throws: a failure is logged (AppLog club.notify_failed) and
 * swallowed, so a push problem can never fail a publish, a tick or a
 * finalize. Admin announcement opt-outs are unrelated.
 */
class ClubCompetitionNotifier
{
    public const EVENT_PUBLISHED = 'published';
    public const EVENT_STARTED   = 'started';
    public const EVENT_FINALIZED = 'finalized';

    private const CHUNK = 200;

    public function __construct(private ExpoPushService $push) {}

    /** @return array{event: string, claimed: bool, recipients: int, sent: int, failed: int} */
    public function published(ClubCompetition $competition): array
    {
        return $this->safely($competition, self::EVENT_PUBLISHED, function (ClubCompetition $c) {
            $c->refresh();
            if ($c->state !== CompetitionState::Published) {
                return $this->summary(self::EVENT_PUBLISHED, false);
            }
            if (now()->gte($c->starts_at)) {
                // Published after the start: members get "started", and "published" is closed for good.
                $result = $this->startedNow($c);
                $this->claim($c, 'notified_published_at', fn ($q) => $q);

                return $result;
            }

            if (!$this->claim($c, 'notified_published_at', fn ($q) => $q->where('state', CompetitionState::Published->value))) {
                return $this->summary(self::EVENT_PUBLISHED, false);
            }

            return $this->send($c, self::EVENT_PUBLISHED);
        });
    }

    /** @return array{event: string, claimed: bool, recipients: int, sent: int, failed: int} */
    public function started(ClubCompetition $competition): array
    {
        return $this->safely($competition, self::EVENT_STARTED, function (ClubCompetition $c) {
            $c->refresh();
            $result = $this->startedNow($c);
            if ($result['claimed']) {
                // "published" (upcoming) is no news any more.
                $this->claim($c, 'notified_published_at', fn ($q) => $q);
            }

            return $result;
        });
    }

    /** @return array{event: string, claimed: bool, recipients: int, sent: int, failed: int} */
    public function finalized(ClubCompetition $competition): array
    {
        return $this->safely($competition, self::EVENT_FINALIZED, function (ClubCompetition $c) {
            $c->refresh();
            if (!$this->claim($c, 'notified_finalized_at', fn ($q) => $q->where('state', CompetitionState::Finalized->value))) {
                return $this->summary(self::EVENT_FINALIZED, false);
            }

            return $this->send($c, self::EVENT_FINALIZED);
        });
    }

    /**
     * Published competitions whose start passed and that are still running,
     * without a "started" push yet (the tick's work list).
     *
     * @return \Illuminate\Support\Collection<int, ClubCompetition>
     */
    public function dueForStart(): \Illuminate\Support\Collection
    {
        $now = now();

        return ClubCompetition::where('state', CompetitionState::Published->value)
            ->whereNull('notified_started_at')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->orderBy('id')
            ->get();
    }

    // ------------------------------------------------------------------

    private function startedNow(ClubCompetition $c): array
    {
        $now = now();
        $claimed = $this->claim($c, 'notified_started_at', fn ($q) => $q
            ->where('state', CompetitionState::Published->value)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now));

        return $claimed ? $this->send($c, self::EVENT_STARTED) : $this->summary(self::EVENT_STARTED, false);
    }

    /** Atomic claim of a notified_* column: true for exactly one caller. */
    private function claim(ClubCompetition $c, string $column, \Closure $conditions): bool
    {
        $query = DB::table('club_competitions')->where('id', $c->id)->whereNull($column);

        return $conditions($query)->update([$column => now()]) === 1;
    }

    private function send(ClubCompetition $c, string $event): array
    {
        $result = $this->summary($event, true);
        $club   = $c->club()->first();

        if ($club === null || !$club->isActive()) {
            AppLog::event('club.notify_skipped', ['competition_id' => $c->id, 'event' => $event, 'reason' => 'club_inactive']);

            return $result;
        }
        if (!config('ballspot.notifications.push_enabled')) {
            AppLog::event('club.notify_skipped', ['competition_id' => $c->id, 'event' => $event, 'reason' => 'push_disabled']);

            return $result;
        }

        $placements = $event === self::EVENT_FINALIZED
            ? ClubCompetitionFinish::where('club_competition_id', $c->id)->get(['user_id', 'placement', 'total_players'])->keyBy('user_id')
            : collect();

        $this->recipients($club)->chunkById(self::CHUNK, function ($users) use ($c, $club, $event, $placements, &$result) {
            $messages = [];
            foreach ($users as $user) {
                [$title, $body] = $this->copy($c, $club, $event, $user, $placements->get($user->id));
                $result['recipients']++;
                foreach ($user->pushTokens as $token) {
                    $messages[] = [
                        'to'    => $token->token,
                        'title' => $title,
                        'body'  => $body,
                        'sound' => 'default',
                        'data'  => ['type' => 'club_competition', 'club_id' => $club->public_id, 'competition_id' => $c->public_id],
                    ];
                }
            }
            if ($messages === []) {
                return;
            }
            $outcome = $this->push->sendMessages($messages);
            $result['sent']   += $outcome['sent'];
            $result['failed'] += $outcome['failed'];
        });

        AppLog::event('club.notify', ['competition_id' => $c->id, 'club_id' => $club->id] + $result);

        return $result;
    }

    private function recipients(Club $club): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->whereNull('anonymized_at')
            ->whereIn('id', ClubMember::query()->select('user_id')
                ->where('club_id', $club->id)
                ->where('status', ClubMemberStatus::Active->value))
            ->whereHas('pushTokens')
            ->whereDoesntHave('notificationSetting', fn ($q) => $q->where('club_updates_enabled', false))
            ->with('pushTokens:id,user_id,token')
            ->select('users.id', 'users.preferred_language');
    }

    /** @return array{0: string, 1: string} title + body in the recipient's language */
    private function copy(ClubCompetition $c, Club $club, string $event, User $user, ?ClubCompetitionFinish $finish): array
    {
        $locale = $user->preferredLocale();
        $vars   = ['club' => $club->name, 'competition' => $c->name];

        return match ($event) {
            self::EVENT_PUBLISHED => [
                __('clubs.push.competition_published_title', $vars, $locale),
                __('clubs.push.competition_published_body', $vars + [
                    'date' => $c->starts_at->copy()->setTimezone($c->timezone ?: 'UTC')->locale($locale)->isoFormat('D MMM, HH:mm'),
                ], $locale),
            ],
            self::EVENT_STARTED => [
                __('clubs.push.competition_started_title', $vars, $locale),
                __('clubs.push.competition_started_body', $vars, $locale),
            ],
            default => [
                __('clubs.push.competition_finalized_title', $vars, $locale),
                $finish !== null
                    ? __('clubs.push.competition_finalized_placed', $vars + ['placement' => (int) $finish->placement, 'total' => (int) $finish->total_players], $locale)
                    : __('clubs.push.competition_finalized_body', $vars, $locale),
            ],
        };
    }

    private function summary(string $event, bool $claimed): array
    {
        return ['event' => $event, 'claimed' => $claimed, 'recipients' => 0, 'sent' => 0, 'failed' => 0];
    }

    private function safely(ClubCompetition $competition, string $event, \Closure $fn): array
    {
        try {
            return $fn($competition);
        } catch (\Throwable $e) {
            AppLog::error('club.notify_failed', ['competition_id' => $competition->id, 'event' => $event, 'exception' => class_basename($e)]);
            report($e);

            return $this->summary($event, false);
        }
    }
}
