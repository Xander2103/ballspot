import { Platform } from 'react-native';
import { apiClient } from './client';
import type {
  ClubCard, ClubDetail, ClubLeaderboardEntry, ClubLeaderboardMeta, ClubMember, ClubMembersMeta,
  ClubPackCard, ClubPackDetail, CompetitionCard, CompetitionListPhase, CursorMeta, CursorPage,
  JoinClubResponse, MyClubsMeta,
} from '../types/club';
import type { PackAttemptResponse, PackGuessResult, PackStartResponse } from '../types/pack';
import type {
  ClubFinish, CompetitionAttempt, CompetitionAttemptResponse, CompetitionDetail, CompetitionLeaderboardMeta,
  ClubReportBody, CreateInviteCodeBody, InviteCode, PhotoSubmission,
} from '../types/club';

/** A picked image to upload (expo-image-picker asset subset). */
export interface UploadablePhoto {
  uri: string;
  fileName?: string | null;
  mimeType?: string | null;
}

function photoFile(photo: UploadablePhoto): { name: string; type: string } {
  const fromName = (photo.fileName ?? '').split('.').pop()?.toLowerCase() ?? '';
  const fromUri = (photo.uri.split('.').pop() ?? '').toLowerCase().split('?')[0].split('#')[0];
  const ext = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'].includes(fromName) ? fromName
    : ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'].includes(fromUri) ? fromUri : 'jpg';
  const type = photo.mimeType && photo.mimeType.startsWith('image/')
    ? photo.mimeType
    : ext === 'jpg' || ext === 'jpeg' ? 'image/jpeg' : `image/${ext}`;
  return { name: `club-photo.${ext === 'jpeg' ? 'jpg' : ext}`, type };
}

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

  // --- Club admin (Wave F) — the backend enforces every ability ------------

  /** Usable codes first, then revoked/expired. */
  inviteCodes: (clubId: string, params: PageParams = {}) =>
    apiClient.request<CursorPage<InviteCode>>(
      `${club(clubId)}/invite-codes${query({ cursor: params.cursor, limit: params.limit })}`,
      { signal: params.signal },
    ),

  /** `grants_role: 'admin'` is owner-only (403 otherwise). */
  createInviteCode: (clubId: string, body: CreateInviteCodeBody) =>
    apiClient.request<{ data: InviteCode }>(`${club(clubId)}/invite-codes`, { method: 'POST', body: JSON.stringify(body) }),

  revokeInviteCode: (clubId: string, codeId: number) =>
    apiClient.request<{ data: InviteCode }>(`${club(clubId)}/invite-codes/${codeId}/revoke`, { method: 'POST' }),

  /** Owner only. */
  changeMemberRole: (clubId: string, userId: number, role: 'admin' | 'member') =>
    apiClient.request<{ data: ClubMember }>(`${club(clubId)}/members/${userId}`, { method: 'PATCH', body: JSON.stringify({ role }) }),

  removeMember: (clubId: string, userId: number, reason?: string) =>
    apiClient.request<{ removed: boolean }>(`${club(clubId)}/members/${userId}/remove`, {
      method: 'POST', body: JSON.stringify(reason ? { reason } : {}),
    }),

  banMember: (clubId: string, userId: number, reason?: string) =>
    apiClient.request<{ banned: boolean }>(`${club(clubId)}/members/${userId}/ban`, {
      method: 'POST', body: JSON.stringify(reason ? { reason } : {}),
    }),

  photos: (clubId: string, params: PageParams = {}) =>
    apiClient.request<CursorPage<PhotoSubmission>>(
      `${club(clubId)}/photos${query({ cursor: params.cursor, limit: params.limit })}`,
      { signal: params.signal },
    ),

  /** One photo per request (multipart, same pattern as avatarApi). `rights_confirmed` must be accepted. */
  uploadPhoto: async (clubId: string, photo: UploadablePhoto, opts: { rightsConfirmed: boolean; note?: string }) => {
    const { name, type } = photoFile(photo);
    const form = new FormData();
    if (Platform.OS === 'web') {
      const res = await fetch(photo.uri);
      const raw = await res.blob();
      const typed = raw.type ? raw : new Blob([raw], { type });
      (form as any).append('photo', typed, name);
    } else {
      form.append('photo', { uri: photo.uri, name, type } as any);
    }
    form.append('rights_confirmed', opts.rightsConfirmed ? '1' : '0');
    if (opts.note && opts.note.trim()) form.append('note', opts.note.trim());
    return apiClient.request<{ data: PhotoSubmission }>(`${club(clubId)}/photos`, { method: 'POST', body: form });
  },

  /** Any active member. 429 after 5 reports per hour. */
  report: (clubId: string, body: ClubReportBody) =>
    apiClient.request<{ data: { id: number; status: string } }>(`${club(clubId)}/reports`, { method: 'POST', body: JSON.stringify(body) }),

  /** The player's own club competition placements (Trophy Room "Club trophies"). */
  myClubFinishes: () =>
    apiClient.request<{ data: ClubFinish[] }>('/me/club-finishes').then((r) => r.data ?? []),
};
