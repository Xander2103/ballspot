/**
 * Club Detail homepage decisions (pure TypeScript, jest-tested).
 *
 * Hierarchy: identity → ACTIVE competitions (primary) → packs → one compact
 * entry to the full competition list (upcoming / finished). Upcoming and
 * finished competitions never get the same prominence as active ones, and the
 * club-wide leaderboard lives behind the ⋯ menu (standings belong to each
 * competition).
 */
import type { ClubDetail, ClubPackCard, CompetitionCard, CompetitionPhase } from '../types/club';

/** Competitions shown as prominent cards on the club homepage: active ones only. */
export function activeCompetitions(club: Pick<ClubDetail, 'competitions_preview'>): CompetitionCard[] {
  return club.competitions_preview.active.filter((c) => c.phase === 'active');
}

/** The next competition that will start, for the quiet "Next up" line when nothing is running. */
export function nextUpcoming(club: Pick<ClubDetail, 'competitions_preview'>): CompetitionCard | null {
  const upcoming = [...club.competitions_preview.upcoming].sort((a, b) => Date.parse(a.starts_at) - Date.parse(b.starts_at));
  return upcoming[0] ?? null;
}

export interface OtherCompetitionsSummary {
  upcoming: number;
  finished: number;
  /** Phase the full list should open on: upcoming first, else completed. */
  openPhase: 'upcoming' | 'completed';
}

/** Counts for the compact "Upcoming & finished competitions" entry (exact counts from the club card). */
export function otherCompetitionsSummary(club: Pick<ClubDetail, 'competitions' | 'competitions_preview'>): OtherCompetitionsSummary {
  const upcoming = Math.max(club.competitions?.upcoming ?? 0, club.competitions_preview.upcoming.length);
  const finished = club.competitions_preview.completed_count ?? 0;
  return { upcoming, finished, openPhase: upcoming > 0 || finished === 0 ? 'upcoming' : 'completed' };
}

export type RankDisplay =
  | { kind: 'ranked'; rank: number; total: number }
  | { kind: 'unranked' };

/** The player's rank on an active competition card (only when the server has one). */
export function rankDisplay(c: Pick<CompetitionCard, 'me' | 'participants_count'>): RankDisplay {
  if (c.me && c.me.rank > 0) {
    return { kind: 'ranked', rank: c.me.rank, total: Math.max(c.participants_count ?? 0, c.me.rank) };
  }
  return { kind: 'unranked' };
}

const RESERVING_ORDER: CompetitionPhase[] = ['active', 'upcoming'];

/**
 * For a competition-reserved pack: the competition to play it in (active first,
 * then upcoming). Null when the pack is not reserved or the server gave no name.
 */
export function reservingCompetition(pack: Pick<ClubPackCard, 'reserved_for_competition' | 'competitions'>): { id: string; name: string } | null {
  if (!pack.reserved_for_competition) return null;
  for (const phase of RESERVING_ORDER) {
    const hit = (pack.competitions ?? []).find((c) => c.phase === phase);
    if (hit) return { id: hit.id, name: hit.name };
  }
  return null;
}

/** 0..1 progress of the player's normal run, or null when there is nothing to show. */
export function packProgressFraction(pack: Pick<ClubPackCard, 'progress'>): number | null {
  const p = pack.progress;
  if (!p || p.status !== 'active' || !p.total_challenges) return null;
  return Math.min(1, Math.max(0, p.completed_count / p.total_challenges));
}

/** How many packs the club homepage shows before "See all". */
export const HOME_PACK_LIMIT = 4;

const PACK_ORDER = { active: 0, new: 1, completed: 2, reserved: 3 } as const;

/**
 * Packs for the club homepage: playable now first (in progress, then new, then
 * completed), competition-reserved last — they are played through the
 * competition card above. Stable within a group (server order).
 */
export function homePacks(packs: ClubPackCard[], limit: number = HOME_PACK_LIMIT): ClubPackCard[] {
  const rank = (p: ClubPackCard): number =>
    p.reserved_for_competition ? PACK_ORDER.reserved
      : p.progress?.status === 'active' ? PACK_ORDER.active
        : p.progress?.status === 'completed' ? PACK_ORDER.completed
          : PACK_ORDER.new;
  return packs
    .map((p, i) => ({ p, i }))
    .sort((a, b) => rank(a.p) - rank(b.p) || a.i - b.i)
    .slice(0, limit)
    .map(({ p }) => p);
}
