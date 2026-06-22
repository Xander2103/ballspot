import { apiClient } from './client';
import { League } from '../types/league';
import { LeaderboardEntry } from '../types/guess';

// Laravel JsonResource wraps single resources and collections in { data: ... }.
// Leaderboard already models this correctly; all other league endpoints need unwrapping.
interface ResourceResponse<T> { data: T; }
interface LeaderboardResponse { data: LeaderboardEntry[]; }

export const leagueApi = {
  list: () =>
    apiClient.request<ResourceResponse<League[]>>('/leagues').then(r => r.data),

  create: (data: { name: string; duration_days: number; rounds_per_day: number }) =>
    apiClient.request<ResourceResponse<League>>('/leagues', { method: 'POST', body: JSON.stringify(data) }).then(r => r.data),

  join: (join_code: string) =>
    apiClient.request<ResourceResponse<League>>('/leagues/join', { method: 'POST', body: JSON.stringify({ join_code }) }).then(r => r.data),

  get: (id: number) =>
    apiClient.request<ResourceResponse<League>>(`/leagues/${id}`).then(r => r.data),

  leaderboard: (id: number) =>
    apiClient.request<LeaderboardResponse>(`/leagues/${id}/leaderboard`),
};
