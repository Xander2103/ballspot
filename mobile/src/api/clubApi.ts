import { apiClient } from './client';
import type {
  ClubCard, ClubDetail, ClubLeaderboardEntry, ClubLeaderboardMeta, ClubMember, ClubMembersMeta,
  ClubPackCard, ClubPackDetail, CompetitionCard, CompetitionListPhase, CursorMeta, CursorPage,
  JoinClubResponse, MyClubsMeta,
} from '../types/club';
import type { PackAttemptResponse, PackGuessResult, PackStartResponse } from '../types/pack';
import type {
  ClubFinish, CompetitionAttempt, CompetitionAttemptResponse, CompetitionDetail, CompetitionLeaderboardMeta,
} from '../types/club';

/**
 * BallPicker Clubs member API (docs/clubs-implementation-contracts.md §5).
 * Every list is cursor-paginated: pass back `meta.next_cursor` as `cursor`.
 * There is deliberately no call that lists or searches clubs the player has
 * not joined — My Clubs search and Join by code are separate flows.
 */

export interface PageParams {
  cursor?: string | null;
  limit?: number;
  signal?: AbortSignal;
}

function query(params: Record<string, string | number | null | undefined>): string {
  const parts: string[] = [];
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === '') continue;
    parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
  }
  return parts.length ? `?${parts.join('&')}` : '';
}

const club = (clubId: string) => `/clubs/${encodeURIComponent(clubId)}`;
const clubPack = (clubId: string, slug: string) => `${club(clubId)}/packs/${encodeURIComponent(slug)}`;
const competition = (clubId: string, competitionId: string) => `${club(clubId)}/competitions/${encodeURIComponent(competitionId)}`;

export const clubApi = {
  /** My Clubs: only the caller's active/suspended memberships. `q` filters name/city server-side. */
  list: (params: PageParams & { q?: string; sort?: 'recent' | 'name' } = {}) =>
    apiClient.request<CursorPage<ClubCard, MyClubsMeta>>(
      `/clubs${query({ q: params.q, sort: params.sort, cursor: params.cursor, limit: params.limit })}`,
      { signal: params.signal },
    ),

  /** Join with an invite code (normalised, no dash). Idempotent for existing members (`joined: false`). */
  join: (code: string) =>
    apiClient.request<JoinClubResponse>('/clubs/join', { method: 'POST', body: JSON.stringify({ code }) }),

  get: (clubId: string, signal?: AbortSignal) =>
    apiClient.request<{ data: ClubDetail }>(club(clubId), { signal }),

  leave: (clubId: string) =>
    apiClient.request<{ left: boolean }>(`${club(clubId)}/leave`, { method: 'POST' }),

  /** 403 `club_forbidden` when the club hides its member list from ordinary members. */
  members: (clubId: string, params: PageParams & { q?: string } = {}) =>
    apiClient.request<CursorPage<ClubMember, ClubMembersMeta>>(
      `${club(clubId)}/members${query({ q: params.q, cursor: params.cursor, limit: params.limit })}`,
      { signal: params.signal },
    ),

  packs: (clubId: string, params: PageParams = {}) =>
    apiClient.request<CursorPage<ClubPackCard>>(
      `${club(clubId)}/packs${query({ cursor: params.cursor, limit: params.limit })}`,
      { signal: params.signal },
    ),

  /** Same `data` as GET /api/packs/{slug}, plus `reserved_for_competition` and `competitions`. */
  pack: (clubId: string, slug: string) =>
    apiClient.request<{ data: ClubPackDetail }>(clubPack(clubId, slug)),

  /** Normal track. Same body (and 409 replay contract) as POST /api/packs/{slug}/start; 409 `pack_reserved_for_competition`. */
  packStart: (clubId: string, slug: string) =>
    apiClient.request<PackStartResponse>(`${clubPack(clubId, slug)}/start`, { method: 'POST' }),

  packAttempt: (clubId: string, slug: string) =>
    apiClient.request<PackAttemptResponse>(`${clubPack(clubId, slug)}/attempt`),

  /** Club all-time leaderboard (shared ranks). */
  leaderboard: (clubId: string, params: PageParams = {}) =>
    apiClient.request<CursorPage<ClubLeaderboardEntry, ClubLeaderboardMeta>>(
      `${club(clubId)}/leaderboard${query({ cursor: params.cursor, limit: params.limit })}`,
      { signal: params.signal },
    ),

  /** Competition list per phase (used by the club detail "Completed" segment; the competition screens extend this). */
  competitions: (clubId: string, params: PageParams & { phase: CompetitionListPhase }) =>
    apiClient.request<CursorPage<CompetitionCard, CursorMeta>>(
      `${club(clubId)}/competitions${query({ phase: params.phase, cursor: params.cursor, limit: params.limit })}`,
      { signal: params.signal },
    ),

  /** Competition detail: window, rules, linked packs with my per-pack `state`, my entry, leaderboard preview. */
  competition: (clubId: string, competitionId: string) =>
    apiClient.request<{ data: CompetitionDetail }>(competition(clubId, competitionId)),

  /**
   * Start (or resume) a competition attempt. Idempotent: retrying with the same
   * `attempt_number` returns the same attempt; an active attempt is returned as is.
   */
  startCompetitionAttempt: (clubId: string, competitionId: string, slug: string, attemptNumber: number) =>
    apiClient.request<CompetitionAttemptResponse>(
      `${competition(clubId, competitionId)}/packs/${encodeURIComponent(slug)}/attempts`,
      { method: 'POST', body: JSON.stringify({ attempt_number: attemptNumber }) },
    ),

  /** Attempt state + current challenge (owner only, else 404). */
  competitionAttempt: (attemptId: number) =>
    apiClient.request<CompetitionAttemptResponse>(`/club-competition-attempts/${attemptId}`),

  /** Submit a guess. A retry of an accepted guess returns the stored result with `replayed: true`. */
  competitionGuess: (attemptId: number, challengeId: number, guessedX: number, guessedY: number) =>
    apiClient.request<Omit<PackGuessResult, 'progress'> & { progress: CompetitionAttempt; replayed?: boolean }>(
      `/club-competition-attempts/${attemptId}/guess`,
      { method: 'POST', body: JSON.stringify({ challenge_id: challengeId, guessed_x: guessedX, guessed_y: guessedY }) },
    ),

  competitionLeaderboard: (clubId: string, competitionId: string, params: PageParams = {}) =>
    apiClient.request<CursorPage<ClubLeaderboardEntry, CompetitionLeaderboardMeta>>(
      `${competition(clubId, competitionId)}/leaderboard${query({ cursor: params.cursor, limit: params.limit })}`,
      { signal: params.signal },
    ),

  myCompetitionAttempts: (clubId: string, competitionId: string) =>
    apiClient.request<{ data: CompetitionAttempt[] }>(`${competition(clubId, competitionId)}/me/attempts`),

  /** The player's own club competition placements (Trophy Room "Club trophies"). */
  myClubFinishes: () =>
    apiClient.request<{ data: ClubFinish[] }>('/me/club-finishes').then((r) => r.data ?? []),
};
