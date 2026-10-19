import { apiClient } from './client';
import type {
  ClubCard, ClubDetail, ClubLeaderboardEntry, ClubLeaderboardMeta, ClubMember, ClubMembersMeta,
  ClubPackCard, ClubPackDetail, CompetitionCard, CompetitionListPhase, CursorMeta, CursorPage,
  JoinClubResponse, MyClubsMeta,
} from '../types/club';
import type { PackAttemptResponse, PackStartResponse } from '../types/pack';

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
};
