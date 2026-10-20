/**
 * Home screen view models — pure TypeScript (no React Native) so the state
 * decisions behind the Daily card, the sport row and the level ring run under
 * the plain ts-jest runner (see __tests__/homeModel.test.ts). The components
 * in components/home only render what these return.
 */

import type { TodayResponse, DailyStats } from '../types/daily';
import type { PlayerRank } from '../types/auth';
import type { Sport } from '../types/sport';

// --- Daily card --------------------------------------------------------------

export type DailyCardModel =
  | { kind: 'loading' }
  /** No daily to play for the selected sport today. */
  | { kind: 'none'; sportName: string | null; isOtherSport: boolean }
  /** Played today: the action opens the result instead of the game. */
  | { kind: 'played'; dailyChallengeId: number; progress: MonthProgress | null; streak: number }
  | { kind: 'playable'; dailyChallengeId: number; progress: MonthProgress | null; info: string | null; streak: number };

export interface MonthProgress {
  index: number;
  total: number;
}

/**
 * Same decisions the previous Home card made, in one place:
 *  - still loading → loading card
 *  - no daily (or a has_daily response without an entry) → empty card, no action
 *  - already played → "View Today's Result"
 *  - otherwise → "Play Daily Challenge", with category · difficulty when known
 * Month progress ("Day 4 of 31") only when the backend sends both numbers.
 */
export function dailyCardModel(loading: boolean, today: TodayResponse | null, stats: DailyStats | null): DailyCardModel {
  if (loading) return { kind: 'loading' };

  const entry = today?.has_daily ? today.daily_challenge : undefined;
  if (!entry) {
    const sportName = today?.sport?.name ?? null;
    return { kind: 'none', sportName, isOtherSport: !!sportName && today?.sport?.slug !== 'football' };
  }

  const index = entry.daily_month_index;
  const total = entry.daily_month_total;
  const progress = index && total ? { index, total } : null;
  const streak = stats?.current_streak ?? 0;

  if (today?.already_played) {
    return { kind: 'played', dailyChallengeId: entry.id, progress, streak };
  }

  const challenge = entry.challenge;
  const parts = [challenge?.category?.name, challenge?.difficulty].filter((p): p is string => !!p);
  return { kind: 'playable', dailyChallengeId: entry.id, progress, info: parts.length ? parts.join(' · ') : null, streak };
}

// --- Sport row ---------------------------------------------------------------

export type SportTapAction = 'none' | 'comingSoon' | 'select';

/**
 * What tapping a sport chip does — the rules SportSelectionScreen applies:
 * coming-soon sports are visible but never saved, the current sport is a no-op.
 */
export function sportTapAction(sport: Pick<Sport, 'id' | 'is_playable'>, selectedId: number | null | undefined): SportTapAction {
  if (!sport.is_playable) return 'comingSoon';
  if (sport.id === selectedId) return 'none';
  return 'select';
}

// --- Level ring --------------------------------------------------------------

export interface LevelRingModel {
  level: number;
  /** 0..1 share of the arc to fill. */
  progress: number;
}

/** Level + progress to the next rank, or null when no rank is known (the ring is hidden). */
export function levelRingModel(rank: PlayerRank | null | undefined): LevelRingModel | null {
  if (!rank || !Number.isFinite(rank.level) || rank.level < 1) return null;
  if (rank.is_max_rank) return { level: rank.level, progress: 1 };
  const pct = Number(rank.progress_to_next_rank_pct);
  const progress = Number.isFinite(pct) ? Math.min(Math.max(pct, 0), 100) / 100 : 0;
  return { level: rank.level, progress };
}
