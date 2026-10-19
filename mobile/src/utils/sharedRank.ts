/**
 * Shared ("tied") ranks on leaderboards (pure, unit tested).
 *
 * The backend uses standard competition ranking: players equal on every
 * tie-break share a rank and the next rank is skipped (1, 2, 2, 4). The app
 * never re-ranks; it only renders what it gets, marking ranks that are shared
 * by more than one loaded row.
 */

export interface RankedLike {
  rank: number;
}

/** For each row: does another loaded row carry the same rank? */
export function sharedRankFlags(entries: RankedLike[]): boolean[] {
  const counts = new Map<number, number>();
  for (const e of entries) counts.set(e.rank, (counts.get(e.rank) ?? 0) + 1);
  return entries.map((e) => (counts.get(e.rank) ?? 0) > 1);
}

/** Set of ranks that are shared within the loaded rows. */
export function sharedRanks(entries: RankedLike[]): Set<number> {
  const flags = sharedRankFlags(entries);
  const out = new Set<number>();
  entries.forEach((e, i) => { if (flags[i]) out.add(e.rank); });
  return out;
}

export interface RankDisplay {
  /** What goes in the rank column: a medal for 1–3, otherwise "#4" or "=4" when tied. */
  text: string;
  isMedal: boolean;
  shared: boolean;
}

const MEDALS: Record<number, string> = { 1: '🥇', 2: '🥈', 3: '🥉' };

/**
 * Rank column content. Medals keep the existing leaderboard look (two players
 * tied for 2nd both get 🥈); other tied ranks read "=4" instead of "#4".
 */
export function rankDisplay(rank: number, shared = false): RankDisplay {
  const medal = MEDALS[rank];
  if (medal) return { text: medal, isMedal: true, shared };
  if (!Number.isFinite(rank) || rank <= 0) return { text: '–', isMedal: false, shared: false };
  return { text: shared ? `=${rank}` : `#${rank}`, isMedal: false, shared };
}

/**
 * Merge preview rows (top + "me") into one display list without duplicating
 * the current user when they are already in the top rows. Keeps rank order.
 */
export function mergePreviewRows<T extends RankedLike & { user_id: number }>(top: T[], me: T | null | undefined): T[] {
  if (!me || top.some((e) => e.user_id === me.user_id)) return top;
  return [...top, me];
}
