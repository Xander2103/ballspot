import { statsSection, xpProgress, profileTiles, historySummary, formatXp, STATS_SECTIONS } from '../profileModel';
import type { PlayerRank, ProfileStats } from '../../types/auth';

const rank = (overrides: Partial<PlayerRank> = {}): PlayerRank => ({
  name: 'Pro',
  level: 3,
  total_xp: 1240,
  current_rank_min_xp: 1000,
  next_rank_name: 'Elite',
  next_rank_xp: 1500,
  xp_to_next_rank: 260,
  progress_to_next_rank_pct: 48,
  is_max_rank: false,
  ...overrides,
});

const stats: ProfileStats = {
  tournaments_count: 12,
  completed_tournaments_count: 9,
  guesses_count: 140,
  total_score: 3480,
  average_score: 24.9,
  daily_challenges_played: 42,
  average_daily_score: 61,
  best_daily_score: 98,
  current_daily_streak: 5,
  best_daily_streak: 11,
  rank: rank(),
};

describe('statsSection', () => {
  it('accepts the three known sections and defaults everything else to overall', () => {
    expect(statsSection('daily')).toBe('daily');
    expect(statsSection('history')).toBe('history');
    expect(statsSection('overall')).toBe('overall');
    expect(statsSection(undefined)).toBe('overall');
    expect(statsSection('badges')).toBe('overall');
    expect(STATS_SECTIONS).toEqual(['overall', 'daily', 'history']);
  });
});

describe('xpProgress (the old RankCard arithmetic, unchanged)', () => {
  it('passes the server percentage through untouched inside 0..100', () => {
    expect(xpProgress(rank())).toEqual({ totalXp: 1240, pct: 48, isMax: false, nextRankName: 'Elite', xpToNext: 260 });
    expect(xpProgress(rank({ progress_to_next_rank_pct: 83.3 }))?.pct).toBe(83.3);
  });

  it('clamps out-of-range values instead of breaking the bar', () => {
    expect(xpProgress(rank({ progress_to_next_rank_pct: -5 }))?.pct).toBe(0);
    expect(xpProgress(rank({ progress_to_next_rank_pct: 140 }))?.pct).toBe(100);
  });

  it('shows a full bar and no next rank at the top', () => {
    const top = xpProgress(rank({ is_max_rank: true, next_rank_name: null, xp_to_next_rank: null, progress_to_next_rank_pct: 0 }));
    expect(top).toEqual({ totalXp: 1240, pct: 100, isMax: true, nextRankName: null, xpToNext: 0 });
  });

  it('is null without a rank (stats failed to load)', () => {
    expect(xpProgress(null)).toBeNull();
    expect(xpProgress(undefined)).toBeNull();
  });
});

describe('profileTiles', () => {
  it('lists streak, total score and best daily, each linking to its stats section', () => {
    expect(profileTiles(stats)).toEqual([
      { key: 'streak', value: 5, section: 'daily' },
      { key: 'totalScore', value: 3480, section: 'overall' },
      { key: 'bestDaily', value: 98, section: 'daily' },
    ]);
  });
});

describe('historySummary', () => {
  it('prefers a count, then distinguishes empty from failed', () => {
    expect(historySummary(7, false)).toEqual({ kind: 'count', count: 7 });
    expect(historySummary(7, true)).toEqual({ kind: 'count', count: 7 });
    expect(historySummary(0, false)).toEqual({ kind: 'empty' });
    expect(historySummary(0, true)).toEqual({ kind: 'failed' });
  });
});

describe('formatXp', () => {
  it('groups thousands like the rank screens', () => {
    expect(formatXp(1240)).toBe('1,240');
    expect(formatXp(0)).toBe('0');
  });
});
