/**
 * Presentation helpers for the pack completion overview. Pure TS (unit
 * tested); the screen only formats what comes back from the API.
 */

export interface PackCompletionLike {
  total_score: number;
  max_score: number;
  average_score: number;
  average_pct: number;
  completed_count: number;
  total_challenges: number;
  is_perfect: boolean;
}

/** Headline for the completion card, based on the average percentage. */
export function completionHeadline(summary: PackCompletionLike): string {
  if (summary.is_perfect) return 'Perfect pack!';
  if (summary.average_pct >= 85) return 'Outstanding!';
  if (summary.average_pct >= 70) return 'Great run!';
  if (summary.average_pct >= 50) return 'Pack completed!';
  return 'Pack completed';
}

/** "1 challenge" / "10 challenges". */
export function challengeCountLabel(count: number): string {
  return `${count} ${count === 1 ? 'challenge' : 'challenges'}`;
}

/** Average with at most one decimal, no trailing ".0". */
export function formatAverage(average: number): string {
  if (!Number.isFinite(average)) return '0';
  const rounded = Math.round(average * 10) / 10;
  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
}

/** Whole-number percentage clamped to 0..100. */
export function formatPct(pct: number): string {
  if (!Number.isFinite(pct)) return '0%';
  return `${Math.min(100, Math.max(0, Math.round(pct)))}%`;
}
