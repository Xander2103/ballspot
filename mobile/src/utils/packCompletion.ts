/**
 * Presentation helpers for the pack completion overview. Pure TS (unit
 * tested); the screen only formats what comes back from the API.
 */
import { translate } from '../i18n/core';
import type { LanguageCode } from './language';

export interface PackCompletionLike {
  total_score: number;
  max_score: number;
  average_score: number;
  average_pct: number;
  completed_count: number;
  total_challenges: number;
  is_perfect: boolean;
}

/**
 * Headline for the completion card, based on the average percentage.
 * `locale` defaults to the active language; components pass the one from
 * `useI18n()` so the headline re-renders on a language change.
 */
export function completionHeadline(summary: PackCompletionLike, locale?: LanguageCode): string {
  if (summary.is_perfect) return translate('packs.headline.perfect', undefined, locale);
  if (summary.average_pct >= 85) return translate('packs.headline.outstanding', undefined, locale);
  if (summary.average_pct >= 70) return translate('packs.headline.greatRun', undefined, locale);
  if (summary.average_pct >= 50) return translate('packs.headline.completedExcl', undefined, locale);
  return translate('packs.headline.completed', undefined, locale);
}

/** "1 challenge" / "10 challenges". */
export function challengeCountLabel(count: number, locale?: LanguageCode): string {
  return translate('packs.meta.challenges', { count }, locale);
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
