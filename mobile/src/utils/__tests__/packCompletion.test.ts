import { completionHeadline, challengeCountLabel, formatAverage, formatPct } from '../packCompletion';

const base = {
  total_score: 700,
  max_score: 1000,
  average_score: 70,
  average_pct: 70,
  completed_count: 10,
  total_challenges: 10,
  is_perfect: false,
};

describe('completionHeadline', () => {
  it('celebrates a perfect pack first', () => {
    expect(completionHeadline({ ...base, is_perfect: true, average_pct: 100 })).toBe('Perfect pack!');
  });

  it('scales with the average percentage', () => {
    expect(completionHeadline({ ...base, average_pct: 90 })).toBe('Outstanding!');
    expect(completionHeadline({ ...base, average_pct: 70 })).toBe('Great run!');
    expect(completionHeadline({ ...base, average_pct: 55 })).toBe('Pack completed!');
    expect(completionHeadline({ ...base, average_pct: 20 })).toBe('Pack completed');
  });
});

describe('formatting helpers', () => {
  it('pluralises challenge counts', () => {
    expect(challengeCountLabel(1)).toBe('1 challenge');
    expect(challengeCountLabel(10)).toBe('10 challenges');
  });

  it('formats averages with at most one decimal', () => {
    expect(formatAverage(70)).toBe('70');
    expect(formatAverage(66.66)).toBe('66.7');
    expect(formatAverage(NaN)).toBe('0');
  });

  it('clamps percentages', () => {
    expect(formatPct(70.4)).toBe('70%');
    expect(formatPct(140)).toBe('100%');
    expect(formatPct(-3)).toBe('0%');
    expect(formatPct(Infinity)).toBe('0%');
  });
});
