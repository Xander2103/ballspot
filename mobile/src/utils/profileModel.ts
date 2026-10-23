/**
 * Pure view-model helpers for the Profile tab (Profile 2.0) and its stats
 * screen. No React Native imports, so this runs under the plain jest runner.
 */
import type { PlayerRank, ProfileStats } from '../types/auth';

/** Segments of the "Your stats" screen; a Profile row deep-links to one. */
export type StatsSection = 'overall' | 'daily' | 'history';
export const STATS_SECTIONS: readonly StatsSection[] = ['overall', 'daily', 'history'];

/** Route param → section, defaulting to the overall stats for anything unknown. */
export function statsSection(value: unknown): StatsSection {
  return value === 'daily' || value === 'history' ? value : 'overall';
}

export interface XpProgressModel {
  totalXp: number;
  /** 0..100 as the server reports it (clamped, never rounded — same as the old RankCard). */
  pct: number;
  isMax: boolean;
  nextRankName: string | null;
  xpToNext: number;
}

/**
 * The XP card's numbers. The arithmetic is deliberately the old RankCard's:
 * the server's progress_to_next_rank_pct clamped to 0..100, a full bar at the
 * max rank. Nothing is recomputed from XP thresholds on the client.
 */
export function xpProgress(rank: PlayerRank | null | undefined): XpProgressModel | null {
  if (!rank) return null;
  const pct = rank.is_max_rank ? 100 : Math.max(0, Math.min(100, rank.progress_to_next_rank_pct));
  return {
    totalXp: rank.total_xp,
    pct,
    isMax: rank.is_max_rank,
    nextRankName: rank.next_rank_name,
    xpToNext: rank.xp_to_next_rank ?? 0,
  };
}

/** "1,240" — the same grouping the rank screens use. */
export function formatXp(n: number): string {
  return n.toLocaleString('en-US');
}

export type ProfileTileKey = 'streak' | 'totalScore' | 'bestDaily';

export interface ProfileTile {
  key: ProfileTileKey;
  value: number;
  /** Where a tap lands on the stats screen. */
  section: StatsSection;
}

/** The three at-a-glance numbers under the XP card, in display order. */
export function profileTiles(stats: ProfileStats): ProfileTile[] {
  return [
    { key: 'streak', value: stats.current_daily_streak, section: 'daily' },
    { key: 'totalScore', value: stats.total_score, section: 'overall' },
    { key: 'bestDaily', value: stats.best_daily_score, section: 'daily' },
  ];
}

export type HistorySummary =
  | { kind: 'count'; count: number }
  | { kind: 'empty' }
  | { kind: 'failed' };

/** What the History row says under its title. A failed load never hides the row. */
export function historySummary(count: number, failed: boolean): HistorySummary {
  if (count > 0) return { kind: 'count', count };
  return failed ? { kind: 'failed' } : { kind: 'empty' };
}
