import { rivalryLine, daysLeftLabel } from '../rivalry';
import { LeaderboardEntry } from '../../types/guess';

function entry(over: Partial<LeaderboardEntry>): LeaderboardEntry {
  return {
    rank: 1, user_id: 1, username: 'u', name: 'U',
    total_score: 0, guesses_count: 1, avg_score: 0, is_current_user: false,
    ...over,
  };
}

describe('rivalryLine', () => {
  it('says you are leading with the margin over second place', () => {
    const line = rivalryLine([
      entry({ user_id: 1, total_score: 120, is_current_user: true }),
      entry({ user_id: 2, total_score: 95, name: 'Sam' }),
    ]);
    expect(line).toBe('You are leading by 25 points');
  });

  it('uses singular point for a 1-point lead', () => {
    const line = rivalryLine([
      entry({ user_id: 1, total_score: 96, is_current_user: true }),
      entry({ user_id: 2, total_score: 95 }),
    ]);
    expect(line).toBe('You are leading by 1 point');
  });

  it('says you are behind the leader by your own deficit', () => {
    const line = rivalryLine([
      entry({ user_id: 2, total_score: 150, name: 'Sam' }),
      entry({ user_id: 3, total_score: 140, name: 'Kim' }),
      entry({ user_id: 1, total_score: 100, is_current_user: true }),
    ]);
    expect(line).toBe('You are 50 points behind Sam');
  });

  it('reports the leader when the current user has no entry', () => {
    const line = rivalryLine([
      entry({ user_id: 2, total_score: 150, name: 'Sam' }),
      entry({ user_id: 3, total_score: 120, name: 'Kim' }),
    ]);
    expect(line).toBe('Sam leads by 30 points');
  });

  it('says tied when the top scores are equal', () => {
    const line = rivalryLine([
      entry({ user_id: 1, total_score: 100, is_current_user: true }),
      entry({ user_id: 2, total_score: 100 }),
    ]);
    expect(line).toBe("It's currently tied.");
  });

  it('says tied when the current user matches the leader from below', () => {
    const line = rivalryLine([
      entry({ user_id: 2, total_score: 100, name: 'Sam' }),
      entry({ user_id: 1, total_score: 100, is_current_user: true }),
    ]);
    expect(line).toBe("It's currently tied.");
  });

  it('falls back to username when name is empty', () => {
    const line = rivalryLine([
      entry({ user_id: 2, total_score: 150, name: '', username: 'sam99' }),
      entry({ user_id: 1, total_score: 100, is_current_user: true }),
    ]);
    expect(line).toBe('You are 50 points behind sam99');
  });

  it('hides for empty, single-entry, or malformed standings', () => {
    expect(rivalryLine([])).toBeNull();
    expect(rivalryLine(null)).toBeNull();
    expect(rivalryLine(undefined)).toBeNull();
    expect(rivalryLine([entry({ user_id: 1, total_score: 50, is_current_user: true })])).toBeNull();
    expect(rivalryLine([
      entry({ user_id: 1, total_score: undefined as unknown as number }),
      entry({ user_id: 2, total_score: 10 }),
    ])).toBeNull();
  });
});

describe('daysLeftLabel', () => {
  const now = new Date('2026-08-31T12:00:00Z');

  it('shows whole days remaining, rounding up', () => {
    expect(daysLeftLabel('2026-09-05T12:00:00Z', now)).toBe('5 days left');
    expect(daysLeftLabel('2026-09-01T00:00:00Z', now)).toBe('1 day left');
  });

  it('clamps expired tournaments to 0 days', () => {
    expect(daysLeftLabel('2026-08-30T12:00:00Z', now)).toBe('0 days left');
  });

  it('hides when ends_at is missing or invalid', () => {
    expect(daysLeftLabel(null, now)).toBeNull();
    expect(daysLeftLabel(undefined, now)).toBeNull();
    expect(daysLeftLabel('not-a-date', now)).toBeNull();
  });
});
