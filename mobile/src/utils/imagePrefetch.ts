/**
 * Image warm-up rules (image performance pass 2026-11-06). Pure TypeScript so
 * the SECURITY rule is pinned by jest:
 *
 *   Before a guess is scored, only the GUESS image (`hidden_image_url`) may
 *   ever be prefetched. The reveal image is only known from a scored guess's
 *   response, so every reveal prefetch takes that response as input — there is
 *   no code path that can warm a reveal URL earlier.
 *
 * The native side (expo-image's disk + memory cache) lives in
 * services/imagePrefetch.ts; this file only decides WHAT to fetch.
 */
import type { TodayResponse } from '../types/daily';

export type PrefetchFn = (url: string) => Promise<boolean>;

/** Remote http(s) URLs only (bundled assets and file:// need no warm-up). */
export function isRemoteImageUrl(url: unknown): url is string {
  return typeof url === 'string' && /^https?:\/\/\S+$/i.test(url.trim());
}

/**
 * Fire-and-forget prefetcher with de-duplication: a URL that is already warm
 * (or on its way) is not requested again; a failed one may be retried later.
 * Bounded so a long session never grows the bookkeeping without limit.
 */
export function createImagePrefetcher(prefetch: PrefetchFn, maxTracked = 64) {
  const seen = new Set<string>();

  return function prefetchImage(url: string | null | undefined): boolean {
    if (!isRemoteImageUrl(url)) return false;
    const key = url.trim();
    if (seen.has(key)) return false;
    seen.add(key);
    if (seen.size > maxTracked) {
      const oldest = seen.values().next().value;
      if (oldest !== undefined) seen.delete(oldest);
    }
    let pending: Promise<boolean>;
    try {
      pending = prefetch(key);
    } catch {
      seen.delete(key);
      return false;
    }
    Promise.resolve(pending)
      .then((ok) => { if (!ok) seen.delete(key); })
      .catch(() => { seen.delete(key); });
    return true;
  };
}

/** Home: today's guess image, only while the daily is still to be played. */
export function dailyGuessImageToPrefetch(today: TodayResponse | null | undefined): string | null {
  if (!today || !today.has_daily || today.already_played) return null;
  const url = today.daily_challenge?.challenge?.hidden_image_url ?? null;
  return isRemoteImageUrl(url) ? url : null;
}

/**
 * After a SCORED pack guess: the GUESS image of the next step (the backend's
 * `next_challenge` never carries a ball position or reveal). The reveal of
 * the step just played comes from revealImageAfterGuess(response.result).
 */
export function nextPackGuessImage(response: {
  next_challenge?: { hidden_image_url?: string | null } | null;
} | null | undefined): string | null {
  const url = response?.next_challenge?.hidden_image_url ?? null;
  return isRemoteImageUrl(url) ? url : null;
}

/** After a SCORED daily or tournament guess: its reveal image. */
export function revealImageAfterGuess(result: { reveal_image_url?: string | null } | null | undefined): string | null {
  const url = result?.reveal_image_url ?? null;
  return isRemoteImageUrl(url) ? url : null;
}

/** width / height from the API's optional size fields; null when unknown or invalid. */
export function imageAspect(width: unknown, height: unknown): number | null {
  if (typeof width !== 'number' || typeof height !== 'number') return null;
  if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) return null;
  const aspect = width / height;
  // Guard against nonsense values; real photos sit well inside this range.
  return aspect >= 0.1 && aspect <= 10 ? aspect : null;
}
