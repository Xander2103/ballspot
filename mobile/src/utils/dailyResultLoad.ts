/**
 * Daily result screen loading. Pure TS (unit tested).
 *
 * Only the guess result is required. Today's challenge (image, title) and the
 * player's stats (streak) are decoration: when either request fails the
 * result — and the share button with it — must still show instead of the
 * "result not found" state.
 */

export interface DailyResultLoad<R, T, S> {
  result: R | null;
  today: T | null;
  stats: S | null;
}

export function settleDailyResultLoad<R, T, S>(
  settled: [PromiseSettledResult<R>, PromiseSettledResult<T>, PromiseSettledResult<S>],
): DailyResultLoad<R, T, S> {
  const [result, today, stats] = settled;
  return {
    result: result.status === 'fulfilled' ? result.value : null,
    today: today.status === 'fulfilled' ? today.value : null,
    stats: stats.status === 'fulfilled' ? stats.value : null,
  };
}
