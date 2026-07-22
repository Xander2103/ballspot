import { apiClient } from './client';
import type { TodayResponse, DailyGuessResult, WeeklyLeaderboard, DailyStats } from '../types/daily';

export const dailyApi = {
  // Optionally scope to a sport slug; omitted uses the user's saved preference.
  today: (sportSlug?: string) =>
    apiClient.request<TodayResponse>(
      `/daily/today${sportSlug ? `?sport=${encodeURIComponent(sportSlug)}` : ''}`
    ),

  guess: (dailyChallengeId: number, guessXRatio: number, guessYRatio: number) =>
    apiClient.request<{ data: DailyGuessResult }>(`/daily/${dailyChallengeId}/guess`, {
      method: 'POST',
      body: JSON.stringify({ guess_x_ratio: guessXRatio, guess_y_ratio: guessYRatio }),
    }),

  result: (dailyChallengeId: number) =>
    apiClient.request<{ data: DailyGuessResult }>(`/daily/${dailyChallengeId}/result`),

  weeklyLeaderboard: () =>
    apiClient.request<WeeklyLeaderboard>('/daily/leaderboard/weekly'),

  stats: () =>
    apiClient.request<DailyStats>('/daily/stats'),
};
