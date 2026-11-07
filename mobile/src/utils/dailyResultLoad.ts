/**
 * Daily result screen loading. Pure TS (unit tested).
 *
 * Only the guess result is required. The player's stats (streak, competition
 * period) are decoration: when that request fails the result — and the share
 * button with it — must still show instead of the "result not found" state.
 *
 * Everything shown about the challenge comes from the result itself, never from
 * /daily/today: that is whichever daily is live now, not the one that was played.
 */

import type { DailyGuessResult } from '../types/daily';
import { visibleChallengeLabel } from './competitionPeriod';
import { imageAspect } from './imagePrefetch';

export interface DailyResultLoad<R, S> {
  result: R | null;
  stats: S | null;
}

export function settleDailyResultLoad<R, S>(
  settled: [PromiseSettledResult<R>, PromiseSettledResult<S>],
): DailyResultLoad<R, S> {
  const [result, stats] = settled;
  return {
    result: result.status === 'fulfilled' ? result.value : null,
    stats: stats.status === 'fulfilled' ? stats.value : null,
  };
}

/**
 * "14 July 2026" for a Y-m-d challenge date. Built from the parts: new Date('2026-07-14')
 * means midnight UTC, which is still the 13th for a player west of Greenwich.
 */
export function formatChallengeDate(date: string | null | undefined, locale: string): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(date ?? '');
  if (!match) return null;
  const local = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  return local.toLocaleDateString(locale === 'en' ? 'en-US' : locale, { day: 'numeric', month: 'long', year: 'numeric' });
}

export interface DailyResultDisplay {
  imageUrl: string | null;
  /** width/height of that image when the API sent its size (null = unknown). */
  imageAspect: number | null;
  isRevealImage: boolean;
  title: string | null;
  categoryName: string | null;
  sportSlug: string | null;
}

export function dailyResultDisplay(
  result: Pick<DailyGuessResult, 'reveal_image_url' | 'challenge' | 'sport'>
    & Partial<Pick<DailyGuessResult, 'reveal_image_width' | 'reveal_image_height'>>,
): DailyResultDisplay {
  const challenge = result.challenge;
  return {
    imageUrl: result.reveal_image_url ?? challenge?.hidden_image_url ?? null,
    imageAspect: result.reveal_image_url
      ? imageAspect(result.reveal_image_width, result.reveal_image_height)
      : imageAspect(challenge?.hidden_image_width, challenge?.hidden_image_height),
    isRevealImage: !!result.reveal_image_url,
    title: challenge?.title ?? null,
    categoryName: visibleChallengeLabel(challenge?.category?.name),
    sportSlug: result.sport?.slug ?? null,
  };
}
