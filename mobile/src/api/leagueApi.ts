import { apiClient } from './client';
import { League } from '../types/league';
import { LeaderboardEntry } from '../types/guess';
import type { LeaderboardMeta } from '../types/daily';
import type { TournamentAvailability } from '../utils/tournamentErrors';

interface ResourceResponse<T> { data: T; }
interface LeaderboardResponse { data: LeaderboardEntry[]; meta?: LeaderboardMeta<LeaderboardEntry>; }

export const leagueApi = {
  list: () =>
    apiClient.request<ResourceResponse<League[]>>('/leagues').then(r => r.data),

  /** Can a tournament of this length be created right now? (pool check) */
  availability: (params: { duration_days?: number; sport?: string | null } = {}) => {
    const qs = new URLSearchParams();
    if (params.duration_days) qs.set('duration_days', String(params.duration_days));
    if (params.sport) qs.set('sport', params.sport);
    const query = qs.toString();
    return apiClient.request<TournamentAvailability>(`/tournaments/availability${query ? `?${query}` : ''}`);
  },

  create: (data: { name: string; duration_days: number; rounds_per_day: number; sport_id?: number | null }) =>
    apiClient.request<ResourceResponse<League>>('/leagues', { method: 'POST', body: JSON.stringify(data) }).then(r => r.data),

  join: (join_code: string) =>
    apiClient.request<ResourceResponse<League>>('/leagues/join', { method: 'POST', body: JSON.stringify({ join_code }) }).then(r => r.data),

  get: (id: number) =>
    apiClient.request<ResourceResponse<League>>(`/leagues/${id}`).then(r => r.data),

  start: (id: number) =>
    apiClient.request<ResourceResponse<League>>(`/leagues/${id}/start`, { method: 'POST' }).then(r => r.data),

  cancel: (id: number) =>
    apiClient.request<void>(`/leagues/${id}`, { method: 'DELETE' }),

  /** Remove a FINISHED tournament from this user's list. History is kept. */
  hide: (id: number) =>
    apiClient.request<void>(`/leagues/${id}/hide`, { method: 'POST' }),

  removeMember: (leagueId: number, userId: number) =>
    apiClient.request<void>(`/leagues/${leagueId}/members/${userId}`, { method: 'DELETE' }),

  leaderboard: (id: number) =>
    apiClient.request<LeaderboardResponse>(`/leagues/${id}/leaderboard`),
};
