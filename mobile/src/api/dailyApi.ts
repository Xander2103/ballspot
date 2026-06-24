import { apiClient } from './client';
import type { TodayResponse, DailyGuessResult, WeeklyLeaderboard, DailyStats } from '../types/daily';

export const dailyApi = {
  today: () =>
    apiClient.request<TodayResponse>('/daily/today'),

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
