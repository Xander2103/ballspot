import {
  dailyCardModel, sportTapAction, levelRingModel,
  nextDailyTarget, countdownParts, msUntilNextTick, nextDailyWhen,
} from '../homeModel';
import type { TodayResponse, DailyStats } from '../../types/daily';
import type { PlayerRank } from '../../types/auth';

function entry(overrides: Partial<NonNullable<TodayResponse['daily_challenge']>> = {}): NonNullable<TodayResponse['daily_challenge']> {
  return {
    id: 42,
    challenge_date: '2026-10-18',
    daily_month_index: 18,
    daily_month_total: 31,
    challenge: {
      id: 7,
      title: 'Hidden',
      difficulty: 'medium',
      hidden_image_url: null,
      category: { id: 1, name: 'Premier League', slug: 'premier-league' },
    },
    ...overrides,
  };
}

const stats: DailyStats = { current_streak: 2, best_streak: 5, total_played: 9, average_score: 700, best_score: 950, weekly_rank: null };

describe('dailyCardModel', () => {
  it('shows the loading card while the daily request is in flight', () => {
    expect(dailyCardModel(true, null, null)).toEqual({ kind: 'loading' });
  });

  it('a playable daily carries the id, month progress, category · difficulty and streak', () => {
    const model = dailyCardModel(false, { has_daily: true, already_played: false, daily_challenge: entry() }, stats);
    expect(model).toEqual({ kind: 'playable', dailyChallengeId: 42, progress: { index: 18, total: 31 }, info: 'Premier League · medium', streak: 2 });
  });

  it('an already-played daily opens the result, not the game', () => {
    const model = dailyCardModel(false, { has_daily: true, already_played: true, daily_challenge: entry() }, stats);
    expect(model).toEqual({ kind: 'played', dailyChallengeId: 42, progress: { index: 18, total: 31 }, streak: 2 });
  });

  it('no daily today has no action and names a non-football sport', () => {
    const tennis = { slug: 'tennis', name: 'Tennis', emoji: '🎾', primary_color: '#cddc39' };
    expect(dailyCardModel(false, { has_daily: false, already_played: false, sport: tennis }, stats))
      .toEqual({ kind: 'none', sportName: 'Tennis', isOtherSport: true });
    const football = { ...tennis, slug: 'football', name: 'Football' };
    expect(dailyCardModel(false, { has_daily: false, already_played: false, sport: football }, null))
      .toEqual({ kind: 'none', sportName: 'Football', isOtherSport: false });
  });

  it('a failed today request (null) and a has_daily response without an entry never show a Play action', () => {
    expect(dailyCardModel(false, null, stats).kind).toBe('none');
    expect(dailyCardModel(false, { has_daily: true, already_played: false }, stats).kind).toBe('none');
  });

  it('omits month progress on older servers and treats missing stats as no streak', () => {
    const model = dailyCardModel(false, {
      has_daily: true,
      already_played: false,
      daily_challenge: entry({ daily_month_index: undefined, daily_month_total: undefined, challenge: { ...entry().challenge, category: null } }),
    }, null);
    expect(model).toEqual({ kind: 'playable', dailyChallengeId: 42, progress: null, info: 'medium', streak: 0 });
  });
});

describe('sportTapAction', () => {
  it('coming-soon sports are never saved, even when they are the stored preference', () => {
    expect(sportTapAction({ id: 2, is_playable: false }, 1)).toBe('comingSoon');
    expect(sportTapAction({ id: 2, is_playable: false }, 2)).toBe('comingSoon');
  });

  it('the selected sport is a no-op; another playable sport is selected', () => {
    expect(sportTapAction({ id: 1, is_playable: true }, 1)).toBe('none');
    expect(sportTapAction({ id: 3, is_playable: true }, 1)).toBe('select');
    expect(sportTapAction({ id: 3, is_playable: true }, null)).toBe('select');
  });
});

describe('levelRingModel', () => {
  const rank: PlayerRank = {
    name: 'Rookie', level: 3, total_xp: 120, current_rank_min_xp: 100, next_rank_name: 'Pro',
    next_rank_xp: 200, xp_to_next_rank: 80, progress_to_next_rank_pct: 20, is_max_rank: false,
  };

  it('uses the real level and the progress to the next rank', () => {
    expect(levelRingModel(rank)).toEqual({ level: 3, progress: 0.2 });
  });

  it('fills the ring at max rank and clamps odd percentages', () => {
    expect(levelRingModel({ ...rank, is_max_rank: true, progress_to_next_rank_pct: 0 })).toEqual({ level: 3, progress: 1 });
    expect(levelRingModel({ ...rank, progress_to_next_rank_pct: 140 })?.progress).toBe(1);
    expect(levelRingModel({ ...rank, progress_to_next_rank_pct: -5 })?.progress).toBe(0);
  });

  it('hides the ring when no rank is known', () => {
    expect(levelRingModel(null)).toBeNull();
    expect(levelRingModel(undefined)).toBeNull();
    expect(levelRingModel({ ...rank, level: 0 })).toBeNull();
  });
});

describe('next-daily countdown', () => {
  const at = '2026-10-21T00:10:00Z';
  const target = Date.parse(at);
  const none = { has_daily: false, already_played: false, next_daily: { date: '2026-10-21', available_at: at } };

  it('counts down only to a time the backend confirmed', () => {
    const model = dailyCardModel(false, none, null);
    expect(nextDailyTarget(model, none)).toBe(target);
    const unconfirmed = { has_daily: false, already_played: false };
    expect(nextDailyTarget(dailyCardModel(false, unconfirmed, null), unconfirmed)).toBeNull();
    const nulled = { ...none, next_daily: null };
    expect(nextDailyTarget(dailyCardModel(false, nulled, null), nulled)).toBeNull();
    const garbage = { ...none, next_daily: { date: 'x', available_at: 'not a date' } };
    expect(nextDailyTarget(dailyCardModel(false, garbage, null), garbage)).toBeNull();
  });

  it('never counts down next to a playable daily, nor while loading', () => {
    const playable = { has_daily: true, already_played: false, daily_challenge: entry(), next_daily: none.next_daily };
    expect(nextDailyTarget(dailyCardModel(false, playable, stats), playable)).toBeNull();
    expect(nextDailyTarget(dailyCardModel(true, none, null), none)).toBeNull();
  });

  it('counts down to tomorrow once today is played', () => {
    const played = { has_daily: true, already_played: true, daily_challenge: entry(), next_daily: none.next_daily };
    expect(nextDailyTarget(dailyCardModel(false, played, stats), played)).toBe(target);
  });

  it('rounds up to the minute and never shows zero or a negative time', () => {
    expect(countdownParts(target, target - (5 * 3600 + 42 * 60) * 1000)).toEqual({ hours: 5, minutes: 42 });
    expect(countdownParts(target, target - (5 * 3600 + 41 * 60 + 30) * 1000)).toEqual({ hours: 5, minutes: 42 });
    expect(countdownParts(target, target - 1000)).toEqual({ hours: 0, minutes: 1 });
    expect(countdownParts(target, target - 60 * 60 * 1000)).toEqual({ hours: 1, minutes: 0 });
    expect(countdownParts(target, target)).toBeNull();
    expect(countdownParts(target, target + 5000)).toBeNull();
  });

  it('wakes up exactly when the shown minute changes', () => {
    expect(msUntilNextTick(target, target - 90_000)).toBe(30_000);
    expect(msUntilNextTick(target, target - 120_000)).toBe(60_000);
    expect(msUntilNextTick(target, target - 1)).toBe(1);
    expect(msUntilNextTick(target, target)).toBe(0);
    expect(msUntilNextTick(target, target + 1)).toBe(0);
  });

  it('names the local day the next daily lands on', () => {
    const now = new Date(2026, 9, 20, 22, 0).getTime();
    expect(nextDailyWhen(null, now)).toEqual({ when: 'tomorrow' });
    expect(nextDailyWhen(new Date(2026, 9, 20, 23, 30).getTime(), now)).toEqual({ when: 'laterToday' });
    expect(nextDailyWhen(new Date(2026, 9, 21, 2, 0).getTime(), now)).toEqual({ when: 'tomorrow' });
    const later = new Date(2026, 9, 23, 2, 0).getTime();
    expect(nextDailyWhen(later, now)).toEqual({ when: 'later', date: new Date(later) });
  });
});
