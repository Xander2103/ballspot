<?php

namespace App\Services;

use App\Models\AppEvent;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\User;
use App\Models\XpEvent;
use Carbon\Carbon;

/**
 * Share-based XP / trophy rewards (v1.9.12 growth features).
 *
 * The app calls POST /api/share right after the native share sheet opened.
 * We cannot know whether anything was actually posted (no share API reports
 * that reliably), so opening the sheet IS the completed share action. Every
 * amount and every limit lives here — the client sends only the share type
 * and, for a daily result, the daily challenge id; the score comes from the
 * user's own stored guess, never from the request.
 *
 * Anti-farming — all rewards are idempotent and bounded:
 *  - first share: once per account (ledger key share_first/1)
 *  - daily result share: once per calendar day (ledger key share_daily/YYYYMMDD)
 *  - high-score (80+) daily share bonus: once per calendar day (share_daily_high_score/YYYYMMDD)
 *  - trophies: once per account (user_badges unique index)
 * The xp_events unique index on (user, source_type, source_id) is the race
 * guard, so two concurrent claims can never double-award.
 *
 * Store reviews are NOT part of this service on purpose: rating / reviewing
 * the app never grants XP, a badge or any other benefit (see GrowthController).
 */
class ShareRewardService
{
    public const TYPE_APP          = 'app';
    public const TYPE_DAILY_RESULT = 'daily_result';
    public const TYPES = [self::TYPE_APP, self::TYPE_DAILY_RESULT];

    public const REWARD_FIRST_SHARE            = 'first_share';
    public const REWARD_DAILY_SHARE            = 'daily_share';
    public const REWARD_DAILY_HIGH_SCORE_SHARE = 'daily_high_score_share';

    public const BADGE_FIRST_SHARE    = 'first_share';
    public const BADGE_DAILY_BRAGGER  = 'daily_bragger';
    public const BADGE_SOCIAL_STRIKER = 'social_striker';

    /**
     * Default XP per reward — the ONE place these numbers live. config/ballspot.php
     * (share.*), the admin Settings page and every fallback read them from here;
     * BALLPICKER_SHARE_* env vars override them per environment.
     *
     * first share: 200 since 2026-10-24 (was 25). The ledger key share_first/1
     * is unchanged, so an account that already claimed the old 25 XP is never
     * awarded again and no historical XP is recalculated.
     */
    public const DEFAULT_FIRST_SHARE_XP      = 200;
    public const DEFAULT_DAILY_SHARE_XP      = 10;
    public const DEFAULT_DAILY_HIGH_SCORE_XP = 15;

    public function __construct(
        private XpService $xpService,
        private BadgeService $badgeService,
        private PlayerRankService $rankService,
        private GrowthEventService $events,
    ) {}

    /**
     * Record the share and award whatever is still available today.
     *
     * @throws ShareRewardException when a daily-result share has no guess to back it.
     */
    public function claim(User $user, string $type, ?DailyChallenge $dailyChallenge = null): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown share type: {$type}");
        }

        $today   = Carbon::today();
        $dayKey  = self::dayKey($today);
        $score   = null;
        $context = [];

        if ($type === self::TYPE_DAILY_RESULT) {
            $guess = $dailyChallenge
                ? DailyChallengeGuess::where('daily_challenge_id', $dailyChallenge->id)->where('user_id', $user->id)->first()
                : null;
            if (!$guess) {
                throw new ShareRewardException('no_guess');
            }
            $score   = (int) $guess->score;
            $context = ['daily_challenge_id' => $dailyChallenge->id, 'score' => $score];
        }

        $xpBefore  = $this->xpService->getTotalXp($user);
        $highScore = $score !== null && $score >= $this->highScoreThreshold();

        // 1. The share itself (what the admin Growth page counts).
        $this->events->record($user, $type === self::TYPE_APP ? AppEvent::SHARE_APP_TAPPED : AppEvent::DAILY_RESULT_SHARE_TAPPED, $context);
        if ($highScore) {
            $this->events->record($user, AppEvent::DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED, $context);
        }
        $shareCount = $this->shareCount($user);

        // 2. XP — every award is deduplicated by the ledger.
        $rewards = [];
        $xp      = $this->xpAmounts();

        if ($this->xpService->awardXp($user, XpEvent::SOURCE_SHARE_FIRST, 1, $xp[self::REWARD_FIRST_SHARE], 'First share', $context)) {
            $rewards[] = self::REWARD_FIRST_SHARE;
        }
        if ($type === self::TYPE_DAILY_RESULT) {
            if ($this->xpService->awardXp($user, XpEvent::SOURCE_SHARE_DAILY, $dayKey, $xp[self::REWARD_DAILY_SHARE], 'Daily result shared', $context)) {
                $rewards[] = self::REWARD_DAILY_SHARE;
            }
            if ($highScore && $this->xpService->awardXp($user, XpEvent::SOURCE_SHARE_DAILY_HIGH_SCORE, $dayKey, $xp[self::REWARD_DAILY_HIGH_SCORE_SHARE], 'High-score daily result shared', $context)) {
                $rewards[] = self::REWARD_DAILY_HIGH_SCORE_SHARE;
            }
        }

        // 3. Trophies — award() is idempotent and silently no-ops when the
        //    badge is not seeded yet (XP above still lands).
        $badges = [];
        if ($shareCount >= 1 && ($b = $this->badgeService->award($user, self::BADGE_FIRST_SHARE, $context))) {
            $badges[] = $b;
        }
        if ($highScore && ($b = $this->badgeService->award($user, self::BADGE_DAILY_BRAGGER, $context))) {
            $badges[] = $b;
        }
        if ($shareCount >= $this->socialStrikerShares() && ($b = $this->badgeService->award($user, self::BADGE_SOCIAL_STRIKER, ['shares' => $shareCount]))) {
            $badges[] = $b;
        }
        $badges = array_merge($badges, $this->badgeService->evaluateRankBadges($user));

        $xpAfter  = $this->xpService->getTotalXp($user);
        $xpGained = $xpAfter - $xpBefore;

        if ($rewards !== [] || $badges !== []) {
            $this->events->record($user, AppEvent::SHARE_REWARD_CLAIMED, array_merge($context, [
                'rewards' => array_merge($rewards, array_map(fn ($b) => 'badge:' . $b->code, $badges)),
                'xp'      => $xpGained,
            ]));
        }

        return [
            'share_count'   => $shareCount,
            'xp_awarded'    => $xpGained,
            'rewards'       => $rewards,
            'new_badges'    => $badges,
            'rank_progress' => [
                'xp_gained' => $xpGained,
                'rank'      => $this->rankService->forXp($xpAfter),
            ],
            'rank_up'       => $this->rankService->rankUp($xpBefore, $xpAfter),
            'status'        => $this->status($user, $today),
        ];
    }

    /** What the user can still earn by sharing today (drives the "+XP" hint). */
    public function status(User $user, ?Carbon $today = null): array
    {
        $today  = $today ?? Carbon::today();
        $dayKey = self::dayKey($today);
        $count  = $this->shareCount($user);

        return [
            'day'                        => $today->toDateString(),
            'total_shares'               => $count,
            'first_share_available'      => !$this->xpService->alreadyAwarded($user, XpEvent::SOURCE_SHARE_FIRST, 1),
            'daily_share_available'      => !$this->xpService->alreadyAwarded($user, XpEvent::SOURCE_SHARE_DAILY, $dayKey),
            'daily_high_score_available' => !$this->xpService->alreadyAwarded($user, XpEvent::SOURCE_SHARE_DAILY_HIGH_SCORE, $dayKey),
            'high_score_threshold'       => $this->highScoreThreshold(),
            'social_striker_shares'      => $this->socialStrikerShares(),
            'xp'                         => $this->xpAmounts(),
        ];
    }

    private function shareCount(User $user): int
    {
        return AppEvent::where('user_id', $user->id)->whereIn('event_name', AppEvent::SHARE_EVENTS)->count();
    }

    /** Calendar day as an integer ledger key (20261014). */
    public static function dayKey(Carbon $day): int
    {
        return (int) $day->format('Ymd');
    }

    /** @return array<string,int> */
    private function xpAmounts(): array
    {
        return [
            self::REWARD_FIRST_SHARE            => (int) config('ballspot.share.first_share_xp', self::DEFAULT_FIRST_SHARE_XP),
            self::REWARD_DAILY_SHARE            => (int) config('ballspot.share.daily_share_xp', self::DEFAULT_DAILY_SHARE_XP),
            self::REWARD_DAILY_HIGH_SCORE_SHARE => (int) config('ballspot.share.daily_high_score_xp', self::DEFAULT_DAILY_HIGH_SCORE_XP),
        ];
    }

    private function highScoreThreshold(): int
    {
        return (int) config('ballspot.share.high_score_threshold', 80);
    }

    private function socialStrikerShares(): int
    {
        return (int) config('ballspot.share.social_striker_shares', 5);
    }
}
