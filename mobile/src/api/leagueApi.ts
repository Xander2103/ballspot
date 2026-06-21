import { apiClient } from './client';
import { League } from '../types/league';
import { LeaderboardEntry } from '../types/guess';

interface LeaderboardResponse {
  data: LeaderboardEntry[];
}

export const leagueApi = {
  list: () =>
    apiClient.request<League[]>('/leagues'),

  create: (data: { name: string; duration_days: number; rounds_per_day: number }) =>
    apiClient.request<League>('/leagues', { method: 'POST', body: JSON.stringify(data) }),

  join: (join_code: string) =>
    apiClient.request<League>('/leagues/join', { method: 'POST', body: JSON.stringify({ join_code }) }),

  get: (id: number) =>
    apiClient.request<League>(`/leagues/${id}`),

  leaderboard: (id: number) =>
    apiClient.request<LeaderboardResponse>(`/leagues/${id}/leaderboard`),
};
