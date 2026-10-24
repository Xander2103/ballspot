/**
 * Share features (v1.9.12 growth): share text, CTA prominence and a local
 * mirror of the server-side share reward rules. Pure TS (jest-tested, no
 * React Native imports).
 *
 * The backend is the authority for XP: POST /api/share stores the share
 * event, decides every amount and enforces the per-account / per-calendar-day
 * limits (ShareRewardService). This module only PREDICTS what a share can
 * still earn so the UI can say "+10 XP" before the tap, and keeps a small
 * local state for when the server status cannot be fetched. Nothing here
 * ever grants XP by itself.
 *
 * Store reviews / ratings are deliberately absent: they are never rewarded.
 */
import { translate } from '../i18n/core';
import type { LanguageCode } from './language';

/** The public landing page every share message links to (spec'd URL). */
export const SHARE_URL = 'https://ballpicker.vanmalderstudio.be';

/** A daily score at or above this gets the stronger CTA and the bonus XP. */
export const HIGH_SCORE_SHARE_THRESHOLD = 80;

/** Total shares that unlock the Social Striker trophy (server-enforced). */
export const SOCIAL_STRIKER_SHARES = 5;

/**
 * Display defaults, used only until the server's status has been seen once.
 * The amounts the server reports (GET /api/share/status `xp`) are stored in
 * the share state and always win, so a server-side change never needs an app
 * release and the app can never promise a number the server will not grant.
 * Mirrors ShareRewardService::DEFAULT_* (backend).
 */
export const SHARE_XP = {
  firstShare: 200,
  dailyShare: 10,
  dailyHighScore: 15,
} as const;

export type ShareKind = 'app' | 'daily_result';
export type ShareReward = 'first_share' | 'daily_share' | 'daily_high_score_share';

export function isHighScore(score: number): boolean {
  return Number.isFinite(score) && score >= HIGH_SCORE_SHARE_THRESHOLD;
}

/** "I scored 85 points in today's BallPicker Daily Challenge ⚽🎯 Can you beat me?" + URL. */
export function buildDailyResultShareMessage(score: number, locale?: LanguageCode): string {
  return `${translate('daily.share.message', { score }, locale)}\n${SHARE_URL}`;
}

/** Short invitation used by the Profile "Share BallPicker" button + URL. */
export function buildAppShareMessage(locale?: LanguageCode): string {
  return `${translate('profile.growth.shareAppMessage', undefined, locale)}\n${SHARE_URL}`;
}

export interface DailyShareCta {
  /** Translation key for the button label. */
  key: 'daily.share.ctaHigh' | 'daily.share.cta';
  /** true = primary button above the fold; false = secondary, below. */
  prominent: boolean;
}

/** Every score can be shared; 80+ gets the stronger copy and placement. */
export function dailyShareCta(score: number): DailyShareCta {
  return isHighScore(score)
    ? { key: 'daily.share.ctaHigh', prominent: true }
    : { key: 'daily.share.cta', prominent: false };
}

// ---------------------------------------------------------------------------
// Reward state (local mirror of the server rules)
// ---------------------------------------------------------------------------

export interface ShareXpAmounts {
  first_share: number;
  daily_share: number;
  daily_high_score_share: number;
}

export interface ShareRewardState {
  totalShareCount: number;
  firstShareClaimed: boolean;
  /** YYYY-MM-DD of the last daily-result share that earned the daily XP. */
  lastDailyShareRewardDate: string | null;
  /** YYYY-MM-DD of the last 80+ daily-result share that earned the bonus. */
  lastHighScoreShareRewardDate: string | null;
  /** XP amounts the server last reported; null = not seen yet (display defaults apply). */
  xp: ShareXpAmounts | null;
}

export const INITIAL_SHARE_REWARD_STATE: ShareRewardState = {
  totalShareCount: 0,
  firstShareClaimed: false,
  lastDailyShareRewardDate: null,
  lastHighScoreShareRewardDate: null,
  xp: null,
};

/** GET /api/share/status and the `status` block of POST /api/share. */
export interface ShareRewardStatus {
  day: string;
  total_shares: number;
  first_share_available: boolean;
  daily_share_available: boolean;
  daily_high_score_available: boolean;
  high_score_threshold: number;
  social_striker_shares: number;
  xp: ShareXpAmounts;
}

/** Keep only sane, non-negative integer amounts; anything else → null (use the defaults). */
export function sanitizeShareXp(value: unknown): ShareXpAmounts | null {
  if (!value || typeof value !== 'object') return null;
  const v = value as Record<string, unknown>;
  const ok = (n: unknown): n is number => typeof n === 'number' && Number.isInteger(n) && n >= 0;
  return ok(v.first_share) && ok(v.daily_share) && ok(v.daily_high_score_share)
    ? { first_share: v.first_share, daily_share: v.daily_share, daily_high_score_share: v.daily_high_score_share }
    : null;
}

/** The server wins: rebuild the local state from what it reports. */
export function stateFromServerStatus(status: ShareRewardStatus): ShareRewardState {
  return {
    totalShareCount: Math.max(0, status.total_shares | 0),
    firstShareClaimed: !status.first_share_available,
    lastDailyShareRewardDate: status.daily_share_available ? null : status.day,
    lastHighScoreShareRewardDate: status.daily_high_score_available ? null : status.day,
    xp: sanitizeShareXp(status.xp),
  };
}

/** Local calendar day as YYYY-MM-DD (the per-day limits are calendar days). */
export function localDateString(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

export interface ShareRewardPreview {
  xp: number;
  rewards: ShareReward[];
}

const DEFAULT_AMOUNTS: ShareXpAmounts = {
  first_share: SHARE_XP.firstShare,
  daily_share: SHARE_XP.dailyShare,
  daily_high_score_share: SHARE_XP.dailyHighScore,
};

/**
 * What a share of `kind` would still earn today, by the same rules the server
 * applies: first share once ever, daily-result share once per calendar day,
 * 80+ bonus once per calendar day. Low scores still share — they simply get
 * no bonus.
 */
export function availableShareRewards(
  state: ShareRewardState,
  kind: ShareKind,
  score: number | null,
  today: string,
  amountsOverride?: ShareXpAmounts,
): ShareRewardPreview {
  const amounts = amountsOverride ?? state.xp ?? DEFAULT_AMOUNTS;
  const rewards: ShareReward[] = [];
  let xp = 0;

  if (!state.firstShareClaimed) {
    rewards.push('first_share');
    xp += amounts.first_share;
  }
  if (kind === 'daily_result') {
    if (state.lastDailyShareRewardDate !== today) {
      rewards.push('daily_share');
      xp += amounts.daily_share;
    }
    if (score !== null && isHighScore(score) && state.lastHighScoreShareRewardDate !== today) {
      rewards.push('daily_high_score_share');
      xp += amounts.daily_high_score_share;
    }
  }

  return { xp, rewards };
}

export interface ShareApplied extends ShareRewardPreview {
  state: ShareRewardState;
}

/**
 * Record a share locally and mark the rewards it earned as claimed. Pure:
 * returns the next state. Repeating a share the same day earns nothing more.
 */
export function applyShare(
  state: ShareRewardState,
  kind: ShareKind,
  score: number | null,
  today: string,
  amounts?: ShareXpAmounts,
): ShareApplied {
  const preview = availableShareRewards(state, kind, score, today, amounts);
  const next: ShareRewardState = {
    ...state,
    totalShareCount: state.totalShareCount + 1,
    firstShareClaimed: state.firstShareClaimed || preview.rewards.includes('first_share'),
    lastDailyShareRewardDate: preview.rewards.includes('daily_share') ? today : state.lastDailyShareRewardDate,
    lastHighScoreShareRewardDate: preview.rewards.includes('daily_high_score_share') ? today : state.lastHighScoreShareRewardDate,
  };
  return { ...preview, state: next };
}

/**
 * What the Profile "Share BallPicker" card says about its one-time reward:
 *  - unknown: the state is not loaded yet → neutral copy, no promise;
 *  - available: never claimed on this account → "+200 XP";
 *  - claimed: already awarded (the server says so) → "reward claimed".
 */
export type FirstShareRewardView =
  | { kind: 'unknown' }
  | { kind: 'available'; xp: number }
  | { kind: 'claimed'; xp: number };

export function firstShareRewardView(state: ShareRewardState | null): FirstShareRewardView {
  if (!state) return { kind: 'unknown' };
  const xp = (state.xp ?? DEFAULT_AMOUNTS).first_share;
  if (state.firstShareClaimed) return { kind: 'claimed', xp };
  return xp > 0 ? { kind: 'available', xp } : { kind: 'unknown' };
}
